<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Execution;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\VariableDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\HasFieldsType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\WrappingType;
use GraphQL\Type\Introspection;
use GraphQL\Type\Schema;
use XGraphQL\SchemaGateway\AST\DelegateDirective;
use XGraphQL\SchemaGateway\RelationRegistry;
use XGraphQL\Utils\Variable;

final readonly class QuerySplitter
{
    /**
     * @param Schema $schema
     * @param OperationDefinitionNode $operation
     * @param array<string, FragmentDefinitionNode> $fragments
     * @param array<string, mixed> $variables
     * @param RelationRegistry $relationRegistry
     */
    public function __construct(
        private Schema $schema,
        private OperationDefinitionNode $operation,
        private array $fragments,
        private array $variables,
        private RelationRegistry $relationRegistry,
    ) {
    }

    /**
     * @return iterable<SubQuery>
     * @throws \JsonException
     */
    public function split(): iterable
    {
        $parentType = $this->schema->getOperationType($this->operation->operation);
        $selectionSet = $this->operation->selectionSet;

        /** @var \ArrayObject<string, array> $relationFields */
        $relationFields = new \ArrayObject();

        $subSchemaOperationSelections = $this->splitSelections(
            $parentType,
            $selectionSet,
            $relationFields
        );

        foreach ($subSchemaOperationSelections as $subSchema => $operations) {
            foreach ($operations as $operation => $pathSelections) {
                foreach ($pathSelections as $path => $selections) {
                    $selectionSet = new SelectionSetNode(
                        [
                            'selections' => $selections,
                        ],
                    );

                    $this->removeDifferenceSubSchemaFields($parentType, $selectionSet, $subSchema);

                    $fragments = $this->collectFragments($selectionSet, $subSchema);
                    $variables = $this->collectVariables($selectionSet, $fragments);
                    $operation = $this->createOperation($operation, $selectionSet, $variables);
                    $subRelationFields = new \ArrayObject();

                    foreach ($relationFields as $relationPath => $relationField) {
                        if (str_starts_with($relationPath, $path)) {
                            $subRelationFields[$relationPath] = $relationField;
                        }
                    }

                    $query = new SubQuery($operation, $fragments, $variables, $subSchema, $subRelationFields);

                    yield $path => $query;
                }
            }
        }
    }

    private function splitSelections(
        Type $parentType,
        SelectionSetNode $selectionSet,
        \ArrayObject $relationFields,
        InlineFragmentNode|FragmentSpreadNode $rootFragmentSelection = null,
        string $path = '',
    ): array {
        if ($parentType instanceof WrappingType) {
            $parentType = $parentType->getInnermostType();
        }

        $parentTypename = $parentType->name();
        $selections = [];

        foreach ($selectionSet->selections as $pos => $selection) {
            /** @var FieldNode|FragmentSpreadNode|InlineFragmentNode $selection */

            $type = $subSelectionSet = null;

            if ($selection instanceof FragmentSpreadNode) {
                $fragment = $this->fragments[$selection->name->value];
                $typename = $fragment->typeCondition->name->value;
                $type = $this->schema->getType($typename);
                $subSelectionSet = $fragment->selectionSet;
                $rootFragmentSelection ??= $selection;
            }

            if ($selection instanceof InlineFragmentNode) {
                $typename = $selection->typeCondition->name->value;
                $type = $this->schema->getType($typename);
                $subSelectionSet = $selection->selectionSet;
                $rootFragmentSelection ??= $selection;
            }

            if ($selection instanceof FieldNode && Introspection::TYPE_NAME_FIELD_NAME !== $selection->name->value) {
                assert($parentType instanceof HasFieldsType);

                $fieldName = $selection->name->value;
                $field = $parentType->getField($fieldName);

                if ($this->relationRegistry->hasRelation($parentTypename, $fieldName)) {
                    /// If this field is relation field, we need to replace it with mandatory selection set
                    /// and resolve it in next queries.
                    $relation = $this->relationRegistry->getRelation($parentTypename, $fieldName);
                    $relationSelectionSet = $relation->argResolver->getMandatorySelectionSet($relation);

                    $relationFields[$path] = [$relation, $selection];

                    $selectionSet->selections[$pos] = Parser::fragment(
                        sprintf('... on %s %s', $parentTypename, $relationSelectionSet)
                    );

                    continue;
                }

                $ast = $field->astNode;
                $delegateDirective = null !== $ast ? DelegateDirective::find($ast) : null;

                if (null !== $delegateDirective) {
                    $subSchema = $delegateDirective->subSchema;
                    $operation = $delegateDirective->operation;
                    $selections[$subSchema][$operation][$path] ??= [];

                    if (null === $rootFragmentSelection) {
                        $selections[$subSchema][$operation][$path][] = $selection->cloneDeep();
                    } else {
                        $selections[$subSchema][$operation][$path][] = $rootFragmentSelection->cloneDeep();
                    }
                }

                /// If it is not relation field, recursive to lookup
                $type = $field->getType();
                $subSelectionSet = $selection->selectionSet;
                $fieldAlias = $selection->alias?->value;
                $path = sprintf('%s.%s', $path, $fieldAlias ?? $fieldName);
            }

            if (null !== $type && null !== $subSelectionSet) {
                $selections = array_merge_recursive(
                    $selections,
                    $this->splitSelections(
                        $type,
                        $subSelectionSet,
                        $relationFields,
                        $rootFragmentSelection,
                        $path,
                    ),
                );
            }
        }

        return $selections;
    }

    private function collectFragments(SelectionSetNode $selectionSet, string $subSchemaName): array
    {
        /** @var array<string, FragmentDefinitionNode> $fragments */
        $fragments = [];

        foreach ($selectionSet->selections as $selection) {
            $subSelectionSet = null;

            if ($selection instanceof FragmentSpreadNode) {
                $name = $selection->name->value;
                $fragment = $fragments[$name] = $this->fragments[$name]->cloneDeep();
                $typename = $fragment->typeCondition->name->value;
                $type = $this->schema->getType($typename);

                $this->removeDifferenceSubSchemaFields($type, $fragment->selectionSet, $subSchemaName);

                $subSelectionSet = $fragment->selectionSet;
            }

            if ($selection instanceof InlineFragmentNode) {
                $subSelectionSet = $selection->selectionSet;
            }

            if ($selection instanceof FieldNode && Introspection::TYPE_NAME_FIELD_NAME !== $selection->name->value) {
                $subSelectionSet = $selection->selectionSet;
            }

            if (null !== $subSelectionSet) {
                $fragments += $this->collectFragments($subSelectionSet, $subSchemaName);
            }
        }

        return $fragments;
    }

    private function removeDifferenceSubSchemaFields(
        Type $parentType,
        SelectionSetNode $selectionSet,
        string $subSchemaName
    ): void {
        if ($parentType instanceof WrappingType) {
            $parentType = $parentType->getInnermostType();
        }

        foreach ($selectionSet->selections as $index => $selection) {
            if ($selection instanceof FieldNode && Introspection::TYPE_NAME_FIELD_NAME !== $selection->name->value) {
                assert($parentType instanceof HasFieldsType);

                $fieldName = $selection->name->value;
                $field = $parentType->getField($fieldName);
                $ast = $field->astNode;
                $delegateDirective = null !== $ast ? DelegateDirective::find($ast) : null;

                if (null !== $delegateDirective && $subSchemaName !== $delegateDirective->subSchema) {
                    unset($selectionSet->selections[$index]);
                }
            }

            if ($selection instanceof InlineFragmentNode) {
                $typename = $selection->typeCondition->name->value;
                $type = $this->schema->getType($typename);

                $this->removeDifferenceSubSchemaFields($type, $selection->selectionSet, $subSchemaName);
            }
        }

        $selectionSet->selections->reindex();
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
            ...Variable::getVariablesInDirectives($this->operation->directives),
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
            'name' => uniqid('x_graphql_'),
            'operation' => $operation,
            'selectionSet' => $selectionSet,
            'directives' => $this->operation->directives->cloneDeep(),
            'variableDefinitions' => $this->createVariableDefinitions($variables)
        ]);
    }

    private function createVariableDefinitions(array $variables): NodeList
    {
        $definitions = new NodeList([]);

        foreach ($this->operation->variableDefinitions as $definition) {
            /** @var VariableDefinitionNode $definition */
            $name = $definition->variable->name->value;

            if (array_key_exists($name, $variables)) {
                $definitions[] = $definition->cloneDeep();
            }
        }

        return $definitions;
    }
}
