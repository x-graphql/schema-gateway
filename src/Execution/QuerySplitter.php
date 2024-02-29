<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Execution;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\VariableDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\HasFieldsType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\WrappingType;
use GraphQL\Type\Introspection;
use GraphQL\Type\Schema;
use XGraphQL\SchemaGateway\AST\DelegateDirective;
use XGraphQL\SchemaGateway\Relation;
use XGraphQL\SchemaGateway\RelationMandatorySelectionSetProviderInterface;
use XGraphQL\SchemaGateway\RelationRegistry;
use XGraphQL\Utils\Variable;

final readonly class QuerySplitter
{
    /**
     * @param Schema $executionSchema
     * @param OperationDefinitionNode $executionOperation
     * @param array<string, FragmentDefinitionNode> $fragments
     * @param array<string, mixed> $variables
     * @param RelationRegistry $relationRegistry
     */
    public function __construct(
        private Schema $executionSchema,
        private OperationDefinitionNode $executionOperation,
        private array $fragments,
        private array $variables,
        private RelationRegistry $relationRegistry,
    ) {
    }

    /**
     * @return iterable<SubQuery>
     * @throws \JsonException
     */
    public function splitOperation(OperationDefinitionNode $operation): iterable
    {
        $subSchemaOperationSelections = $this->splitSelections($operation, $operation->selectionSet);

        foreach ($subSchemaOperationSelections as $subSchema => $operations) {
            foreach ($operations as $operation => $selections) {
                $subOperationType = $this->executionSchema->getOperationType($operation);
                $selectionSet = new SelectionSetNode(
                    [
                        'selections' => new NodeList(
                            array_map(
                                fn(Node $node) => $node->cloneDeep(),
                                array_values(array_unique($selections)),
                            )
                        ),
                    ],
                );

                $this->removeDifferenceSubSchemaFields($subOperationType, $selectionSet, $subSchema);

                $fragments = $this->collectFragments($subOperationType, $selectionSet, $subSchema);
                $relationFields = $this->collectRelationFields($subOperationType, $selectionSet, $fragments);
                $variables = $this->collectVariables($selectionSet, $fragments);
                $operation = $this->createOperation($operation, $selectionSet, $variables);

                yield new SubQuery($operation, $fragments, $variables, $subSchema, $relationFields);
            }
        }
    }

    private function splitSelections(
        OperationDefinitionNode $operation,
        SelectionSetNode $selectionSet = null,
        InlineFragmentNode|FragmentSpreadNode $rootFragmentSelection = null,
    ): array {
        $operationType = $this->executionSchema->getOperationType($operation->operation);
        $selectionSet ??= $operation->selectionSet;
        $selections = [];

        foreach ($selectionSet->selections as $selection) {
            /** @var FieldNode|FragmentSpreadNode|InlineFragmentNode $selection */

            $subSelectionSet = null;

            if ($selection instanceof FragmentSpreadNode || $selection instanceof InlineFragmentNode) {
                /// Inline fragment and fragment spread MUST have type condition same with parent object type
                $rootFragmentSelection ??= $selection;

                if ($selection instanceof FragmentSpreadNode) {
                    $fragment = $this->fragments[$selection->name->value];
                    $subSelectionSet = $fragment->selectionSet;
                } else {
                    $subSelectionSet = $selection->selectionSet;
                }

                $selections = array_merge_recursive(
                    $selections,
                    $this->splitSelections($operation, $subSelectionSet, $rootFragmentSelection),
                );

                continue;
            }

            if ($selection instanceof FieldNode && Introspection::TYPE_NAME_FIELD_NAME !== $selection->name->value) {
                $fieldName = $selection->name->value;
                $field = $operationType->getField($fieldName);
                /// AST of fields of operation type MUST have delegate directive
                $delegateDirective = DelegateDirective::find($field->astNode);
                $subSchema = $delegateDirective->subSchema;
                $operation = $delegateDirective->operation;
                $selections[$subSchema][$operation] ??= [];

                if (null === $rootFragmentSelection) {
                    $selections[$subSchema][$operation][] = $selection;
                } else {
                    $selections[$subSchema][$operation][] = $rootFragmentSelection;
                }
            }
        }

        return $selections;
    }

    private function collectFragments(
        ObjectType $operationType,
        SelectionSetNode $selectionSet,
        string $subSchemaName
    ): array {
        /** @var array<string, FragmentDefinitionNode> $fragments */
        $fragments = [];

        foreach ($selectionSet->selections as $selection) {
            $subSelectionSet = null;

            if ($selection instanceof FragmentSpreadNode) {
                $name = $selection->name->value;
                $fragment = $fragments[$name] = $this->fragments[$name]->cloneDeep();
                $typename = $fragment->typeCondition->name->value;
                $subSelectionSet = $fragment->selectionSet;

                if ($operationType === $this->executionSchema->getType($typename)) {
                    $this->removeDifferenceSubSchemaFields($operationType, $fragment->selectionSet, $subSchemaName);
                }
            }

            if ($selection instanceof InlineFragmentNode) {
                $subSelectionSet = $selection->selectionSet;
            }

            if ($selection instanceof FieldNode && Introspection::TYPE_NAME_FIELD_NAME !== $selection->name->value) {
                $subSelectionSet = $selection->selectionSet;
            }

            if (null !== $subSelectionSet) {
                $fragments += $this->collectFragments($operationType, $subSelectionSet, $subSchemaName);
            }
        }

        return $fragments;
    }

    private function removeDifferenceSubSchemaFields(
        ObjectType $operationType,
        SelectionSetNode $operationSelectionSet,
        string $subSchemaName
    ): void {
        foreach ($operationSelectionSet->selections as $index => $selection) {
            if ($selection instanceof FieldNode && Introspection::TYPE_NAME_FIELD_NAME !== $selection->name->value) {
                $fieldName = $selection->name->value;
                $field = $operationType->getField($fieldName);
                $delegateDirective = DelegateDirective::find($field->astNode);

                if ($subSchemaName !== $delegateDirective->subSchema) {
                    unset($operationSelectionSet->selections[$index]);
                }
            }

            if ($selection instanceof InlineFragmentNode) {
                $this->removeDifferenceSubSchemaFields($operationType, $selection->selectionSet, $subSchemaName);
            }
        }

        $operationSelectionSet->selections->reindex();
    }

    /**
     * @param SelectionSetNode $selectionSet
     * @param array<string, FragmentDefinitionNode> $fragments
     * @param string $path
     * @return array<string, array{FieldNode, Relation}>
     */
    private function collectRelationFields(Type $parentType, SelectionSetNode $selectionSet, array $fragments, string $path = ''): array
    {
        $relations = [];

        if ($parentType instanceof WrappingType) {
            $parentType = $parentType->getInnermostType();
        }

        foreach ($selectionSet->selections as $pos => $selection) {
            $type = $subSelectionSet = null;

            if ($selection instanceof InlineFragmentNode) {
                $typename = $selection->typeCondition->name->value;
                $type = $this->executionSchema->getType($typename);
                $subSelectionSet = $selection->selectionSet;
            }

            if ($selection instanceof FragmentSpreadNode) {
                $fragment = $fragments[$selection->name->value];
                $typename = $fragment->typeCondition->name->value;
                $type = $this->executionSchema->getType($typename);
                $subSelectionSet = $fragments[$selection->name->value]->selectionSet;
            }

            if ($selection instanceof FieldNode && Introspection::TYPE_NAME_FIELD_NAME !== $selection->name->value) {
                assert($parentType instanceof HasFieldsType);

                $parentTypename = $parentType->name();
                $fieldName = $selection->name->value;
                $fieldAlias = $selection->alias?->value;
                $field = $parentType->getField($fieldName);

                if ($this->relationRegistry->hasRelation($parentTypename, $fieldName)) {
                    /// Expect relation field must have delegate directive
                    $delegateDirective = DelegateDirective::find($field->astNode);
                    $subSchema = $delegateDirective->subSchema;
                    $operation = $delegateDirective->operation;
                    $relation = $this->relationRegistry->getRelation($parentTypename, $fieldName);
                    $relations[$subSchema][$operation][$path][] = [$relation, $selection];

                    if (!$relation->argResolver instanceof RelationMandatorySelectionSetProviderInterface) {
                        unset($selectionSet->selections[$pos]);

                        continue;
                    }

                    /// Replace it with mandatory fields needed to resolve args.
                    $selectionSet->selections[$pos] = Parser::fragment(
                        sprintf(
                            '... on %s %s',
                            $parentTypename,
                            $relation->argResolver->getMandatorySelectionSet($relation)
                        )
                    );
                } else {
                    $path = ltrim(sprintf('%s.%s', $path, $fieldAlias ?? $fieldName), '.');
                    $type = $field->getType();
                    $subSelectionSet = $selection->selectionSet;
                }
            }

            if (null !== $type && null !== $subSelectionSet) {
                $relations = array_merge_recursive(
                    $relations,
                    $this->collectRelationFields($type, $subSelectionSet, $fragments, $path)
                );
            }
        }

        $selectionSet->selections->reindex();

        return $relations;
    }

    /**
     * @param SelectionSetNode $selectionSet
     * @param FragmentDefinitionNode[] $fragments
     * @return array<string, mixed>
     */
    private function collectVariables(SelectionSetNode $selectionSet, array $fragments): array
    {
        $variables = [];
        $names = [
            ...Variable::getVariablesInDirectives($this->executionOperation->directives),
            ...Variable::getVariablesInFragments($fragments),
            ...Variable::getVariablesInSelectionSet($selectionSet),
        ];

        foreach ($names as $name) {
            $variables[$name] = $this->variables[$name];
        }

        return $variables;
    }

    private function createOperation(
        string $operation,
        SelectionSetNode $selectionSet,
        array $variables
    ): OperationDefinitionNode {
        return new OperationDefinitionNode([
            'name' => Parser::name(uniqid('x_graphql_')),
            'operation' => $operation,
            'directives' => $this->executionOperation->directives->cloneDeep(),
            'selectionSet' => $selectionSet,
            'variableDefinitions' => $this->createVariableDefinitions($variables)
        ]);
    }

    private function createVariableDefinitions(array $variables): NodeList
    {
        $definitions = new NodeList([]);

        foreach ($this->executionOperation->variableDefinitions as $definition) {
            /** @var VariableDefinitionNode $definition */
            $name = $definition->variable->name->value;

            if (array_key_exists($name, $variables)) {
                $definitions[] = $definition->cloneDeep();
            }
        }

        return $definitions;
    }
}
