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
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\WrappingType;
use GraphQL\Type\Introspection;
use GraphQL\Type\Schema;
use XGraphQL\SchemaGateway\AST\DelegateDirective;
use XGraphQL\SchemaGateway\AST\RelationDirective;
use XGraphQL\SchemaGateway\Exception\LogicException;
use XGraphQL\SchemaGateway\Relation;
use XGraphQL\Utils\Variable;

final readonly class QuerySplitter
{
    private ObjectType $operationType;

    /**
     * @param iterable<Relation> $relations
     * @param Schema $executionSchema
     * @param OperationDefinitionNode $operation
     * @param FragmentDefinitionNode[] $fragments
     * @param array<string, mixed> $variables
     */
    public function __construct(
        private iterable $relations,
        private Schema $executionSchema,
        private OperationDefinitionNode $operation,
        private array $fragments,
        private array $variables,
    ) {
        $this->operationType = $this->executionSchema->getOperationType($this->operation->operation);

        assert(null !== $this->operationType);
    }

    /**
     * @return iterable<SubQuery>
     * @throws \JsonException
     */
    public function split(): iterable
    {
        $operationSelectionSet = $this->operation->getSelectionSet();
        $subSchemaSelections = $this->collectSubSchemaSelections($operationSelectionSet);

        foreach ($subSchemaSelections as $subSchema => $selections) {
            $selectionSet = new SelectionSetNode(
                [
                    'selections' => new NodeList(
                        array_map(fn(Node $node) => $node->cloneDeep(), $selections),
                    ),
                ],
            );

            $this->removeDifferenceSubSchemaFields($selectionSet, $subSchema);

            $fragments = $this->collectFragments($selectionSet, $subSchema);
            $variables = $this->collectVariables($selectionSet, $fragments);
            $operation = $this->createOperation($selectionSet, $variables);

            $subQuery = new SubQuery($subSchema, $operation, $fragments, $variables);

            $this->resolveSubQueryRelations($subQuery);

            yield $subQuery;
        }
    }

    private function collectSubSchemaSelections(
        SelectionSetNode $selectionSet,
        InlineFragmentNode|FragmentSpreadNode $rootFragmentSelection = null
    ): array {
        $selections = [];

        foreach ($selectionSet->selections as $selection) {
            /** @var FieldNode|FragmentSpreadNode|InlineFragmentNode $selection */

            if (Introspection::TYPE_NAME_FIELD_NAME === $selection->name->value) {
                continue;
            }

            if ($selection instanceof FragmentSpreadNode) {
                $fragment = $this->fragments[$selection->name->value];

                $selections = array_merge_recursive(
                    $selections,
                    $this->collectSubSchemaSelections($fragment->selectionSet, $rootFragmentSelection ?? $selection)
                );

                continue;
            }

            if ($selection instanceof InlineFragmentNode) {
                $selections = array_merge_recursive(
                    $selections,
                    $this->collectSubSchemaSelections($selection->selectionSet, $rootFragmentSelection ?? $selection)
                );

                continue;
            }

            $ast = $this->operationType->getField($selection->name->value)->astNode;
            $owner = DelegateDirective::findSubSchema($ast);
            $selections[$owner] ??= [];

            if (null === $rootFragmentSelection) {
                $selections[$owner][] = $selection;
            } else {
                $selections[$owner][] = $rootFragmentSelection;
            }
        }

        return array_map(fn(array $items) => array_unique($items), $selections);
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

                if ($fragment->typeCondition->name->value === $this->operationType->name()) {
                    $this->removeDifferenceSubSchemaFields($fragment->selectionSet, $subSchemaName);
                }

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

    private function removeDifferenceSubSchemaFields(SelectionSetNode $selectionSet, string $subSchemaName): void
    {
        foreach ($selectionSet->selections as $index => $selection) {
            if ($selection instanceof FieldNode && Introspection::TYPE_NAME_FIELD_NAME !== $selection->name->value) {
                $name = $selection->name->value;
                $fieldDefinition = $this->operationType->getField($name);

                if ($subSchemaName !== DelegateDirective::findSubSchema($fieldDefinition->astNode)) {
                    unset($selectionSet->selections[$index]);
                }
            }

            if ($selection instanceof InlineFragmentNode) {
                $this->removeDifferenceSubSchemaFields($selection->selectionSet, $subSchemaName);
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

    private function createOperation(SelectionSetNode $selectionSet, array $variables): OperationDefinitionNode
    {
        return new OperationDefinitionNode([
            'name' => $this->operation->name?->cloneDeep(),
            'operation' => $this->operation->operation,
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

    private function resolveSubQueryRelations(SubQuery $subQuery, ObjectType $onType = null): void
    {
        $onType ??= $this->operationType;

        $this->resolveSelectionSetRelation($onType, $subQuery->operation->selectionSet, '');
    }

    private function resolveSelectionSetRelation(ObjectType $type, SelectionSetNode $selectionSet, string $path): void
    {
        if ($type instanceof WrappingType) {
            $type = $type->getInnermostType();
        }

        foreach ($selectionSet->selections as $index => $selection) {
            if ($selection instanceof InlineFragmentNode) {
                $inlineFragmentTypename = $selection->typeCondition->name->value;
                $inlineFragmentType = $this->executionSchema->getType($inlineFragmentTypename);

                if ($inlineFragmentType instanceof ObjectType) {
                    $this->resolveSelectionSetRelation($inlineFragmentType, $selectionSet, $path);
                }
            }

            if ($selection instanceof FieldNode && Introspection::TYPE_NAME_FIELD_NAME !== $selection->name->value) {
                $path = sprintf('%s.%s', $path, $selection->alias?->value ?? $selection->name->value);
                $field = $type->getField($selection->name->value);
                $metadata = RelationDirective::findOperationField($field->astNode);

                if (null !== $metadata) {

                    $relation = $this->getRelationByTypeField($type->name(), $field->getName());
                    $additionalSelections = implode(
                        PHP_EOL,
                        $relation->argResolver->getAdditionalSelections($relation)
                    );
                    $selectionSet->selections[$index] = Parser::fragment(
                        sprintf(
                            '...on %s { %s %s }',
                            $type->toString(),
                            /// add __typename to prevents errors in cases empty additional selections
                            Introspection::TYPE_NAME_FIELD_NAME,
                            $additionalSelections,
                        )
                    );

                    /// Sub selection set had been replaced by additional fields, recursive is redundant.
                    continue;
                }

                if (null !== $selection->selectionSet) {
                    $this->collectRelationFields($field->getType(), $selection->selectionSet, $path);
                }
            }
        }
    }

    private function getRelationByTypeField(string $type, string $field): Relation
    {
        static $mapping = null;

        if (null === $mapping) {
            $mapping = [];

            foreach ($this->relations as $relation) {
                $mapping[$relation->onType][$relation->field] = $relation;
            }
        }

        if (isset($mapping[$type][$field])) {
            return $mapping[$type][$field];
        }

        throw new LogicException(sprintf('Not found relation by type: `%s` and field: `%s`', $type, $field));
    }
}
