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
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
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
     * @param array<string, FragmentDefinitionNode> $fragments
     * @param array<string, mixed> $variables
     * @param NodeList<VariableDefinitionNode> $variableDefinitions
     * @param RelationRegistry $relationRegistry
     */
    public function __construct(
        private Schema $executionSchema,
        private array $fragments,
        private array $variables,
        private NodeList $variableDefinitions,
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

        foreach ($subSchemaOperationSelections as $subSchema => $subOperations) {
            foreach ($subOperations as $kind => $selections) {
                $subOperation = $this->createSubOperation($operation, $kind);
                $subOperationType = $this->executionSchema->getOperationType($subOperation->operation);
                $subSelectionSet = $subOperation->selectionSet = new SelectionSetNode(
                    [
                        'selections' => new NodeList(
                            array_map(
                                fn(Node $node) => $node->cloneDeep(),
                                array_values(array_unique($selections)),
                            )
                        ),
                    ],
                );

                $this->removeDifferenceSubSchemaFields($subOperation, $subSchema);

                $fragments = array_map(
                    fn(Node $node) => $node->cloneDeep(),
                    array_unique($this->collectFragments($subSelectionSet))
                );

                foreach ($fragments as $fragment) {
                    if ($fragment->typeCondition->name->value === $subOperationType->name()) {
                        $this->removeDifferenceSubSchemaFields($subOperation, $subSchema, $fragment->selectionSet);
                    }
                }

                $relationFields = $this->collectRelationFields($subOperationType, $subSelectionSet, $fragments);

                /// All selection set is clear, let collects using variables
                $variables = $this->collectVariables($operation, $fragments);

                $subOperation->variableDefinitions = $this->createVariableDefinitions($variables);

                yield new SubQuery($subOperation, $fragments, $variables, $subSchema, $relationFields);
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
                /// Inline fragment and fragment spread on operation type MUST have equals type condition
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

    private function collectFragments(SelectionSetNode $selectionSet): array
    {
        /** @var array<string, FragmentDefinitionNode> $fragments */
        $fragments = [];

        foreach ($selectionSet->selections as $selection) {
            $subSelectionSet = null;

            if ($selection instanceof FragmentSpreadNode) {
                $name = $selection->name->value;
                $fragment = $fragments[$name] = $this->fragments[$name];
                $subSelectionSet = $fragment->selectionSet;
            }

            if ($selection instanceof InlineFragmentNode) {
                $subSelectionSet = $selection->selectionSet;
            }

            if ($selection instanceof FieldNode && Introspection::TYPE_NAME_FIELD_NAME !== $selection->name->value) {
                $subSelectionSet = $selection->selectionSet;
            }

            if (null !== $subSelectionSet) {
                $fragments += $this->collectFragments($subSelectionSet);
            }
        }

        return $fragments;
    }

    private function removeDifferenceSubSchemaFields(
        OperationDefinitionNode $subOperation,
        string $subSchemaName,
        SelectionSetNode $selectionSet = null,
    ): void {
        $subOperationType = $this->executionSchema->getOperationType($subOperation->operation);
        $selectionSet ??= $subOperation->selectionSet;

        foreach ($selectionSet->selections as $index => $selection) {
            if ($selection instanceof FieldNode && Introspection::TYPE_NAME_FIELD_NAME !== $selection->name->value) {
                $fieldName = $selection->name->value;
                $field = $subOperationType->getField($fieldName);
                $delegateDirective = DelegateDirective::find($field->astNode);

                if ($subSchemaName !== $delegateDirective->subSchema) {
                    unset($selectionSet->selections[$index]);
                }
            }

            if ($selection instanceof InlineFragmentNode) {
                $this->removeDifferenceSubSchemaFields($subOperation, $subSchemaName, $selection->selectionSet);
            }
        }

        $selectionSet->selections->reindex();
    }

    /**
     * @param SelectionSetNode $selectionSet
     * @param array<string, FragmentDefinitionNode> $fragments
     * @param string $path
     * @return array<string, array{FieldNode, Relation}>
     */
    private function collectRelationFields(
        Type $parentType,
        SelectionSetNode $selectionSet,
        array $fragments,
        string $path = ''
    ): array {
        $relations = [];

        if ($parentType instanceof NonNull) {
            $parentType = $parentType->getWrappedType();
        }

        $parentTypeIsList = $parentType instanceof ListOfType;

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
                    $path = sprintf(
                        '%s%s.%s',
                        $path,
                        $parentTypeIsList ? '[]' : '',
                        $fieldAlias ?? $fieldName
                    );
                    $path = ltrim($path, '.');
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
     * @param NodeList<DirectiveNode> $directives
     * @return array<string, mixed>
     */
    private function collectVariables(OperationDefinitionNode $operation, array $fragments): array
    {
        $variables = [];
        $names = [
            ...Variable::getVariablesInOperation($operation),
            ...Variable::getVariablesInFragments($fragments),
        ];

        foreach ($names as $name) {
            $variables[$name] = $this->variables[$name];
        }

        return $variables;
    }

    private function createSubOperation(OperationDefinitionNode $fromOperation, string $kind): OperationDefinitionNode
    {
        return new OperationDefinitionNode([
            'name' => Parser::name(uniqid('x_graphql_')),
            'operation' => $kind,
            'directives' => $fromOperation->directives->cloneDeep(),
        ]);
    }

    private function createVariableDefinitions(array $variables): NodeList
    {
        $definitions = new NodeList([]);

        foreach ($this->variableDefinitions as $definition) {
            /** @var VariableDefinitionNode $definition */
            $name = $definition->variable->name->value;

            if (array_key_exists($name, $variables)) {
                $definitions[] = $definition->cloneDeep();
            }
        }

        return $definitions;
    }
}
