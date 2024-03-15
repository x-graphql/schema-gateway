<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Execution;

use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Executor\Values;
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
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\WrappingType;
use GraphQL\Type\Introspection;
use GraphQL\Type\Schema;
use XGraphQL\SchemaGateway\AST\DelegateDirective;
use XGraphQL\SchemaGateway\MandatorySelectionSetProviderInterface;
use XGraphQL\SchemaGateway\Relation;
use XGraphQL\SchemaGateway\RelationRegistry;
use XGraphQL\SchemaGateway\SubSchemaRegistry;
use XGraphQL\Utils\Variable;

final readonly class DelegateResolver
{
    /**
     * @param Schema $executionSchema
     * @param array<string,FragmentDefinitionNode> $fragments
     * @param array<string,mixed> $variables
     * @param OperationDefinitionNode $rootOperation
     * @param RelationRegistry $relationRegistry
     * @param SubSchemaRegistry $subSchemaRegistry
     * @param PromiseAdapter $promiseAdapter
     */
    public function __construct(
        private Schema $executionSchema,
        private OperationDefinitionNode $rootOperation,
        private array $fragments,
        private array $variables,
        private RelationRegistry $relationRegistry,
        private SubSchemaRegistry $subSchemaRegistry,
        private PromiseAdapter $promiseAdapter,
    ) {
    }

    /**
     * @throws Error
     * @throws \JsonException
     * @throws \Exception
     */
    public function resolve(): Promise
    {
        $promises = [];
        $subSchemaOperationSelections = $this->splitSelections();

        foreach ($subSchemaOperationSelections as $subSchemaName => $subOperations) {
            foreach ($subOperations as $kind => $selections) {
                $operation = $this->createOperation($kind);
                $operationType = $this->executionSchema->getOperationType($kind);

                $operation->selectionSet->selections = new NodeList(
                    array_map(
                        static fn (Node $node) => $node->cloneDeep(),
                        array_values(array_unique($selections)),
                    )
                );

                $this->removeDifferenceSubSchemaFields($operation, $subSchemaName);

                $fragments = array_map(
                    static fn (Node $node) => $node->cloneDeep(),
                    array_unique($this->collectFragments($operation->selectionSet))
                );

                foreach ($fragments as $fragment) {
                    if ($fragment->typeCondition->name->value === $operationType->name()) {
                        $this->removeDifferenceSubSchemaFields($operation, $subSchemaName, $fragment->selectionSet);
                    }
                }

                $relations = $this->collectRelations($operationType, $operation->selectionSet, $fragments);

                /// All selection set is clear, let collects using variables
                $variables = $this->collectVariables($operation, $fragments);

                $operation->variableDefinitions = $this->createVariableDefinitions($variables);

                $promise = $this->delegateToExecute($subSchemaName, $operation, $fragments, $variables);

                $promises[] = $promise->then(
                    fn (ExecutionResult $result) => $this->resolveRelations($result, $relations)
                );
            }
        }

        return $this
            ->promiseAdapter
            ->all($promises)
            ->then(
                static fn (array $results) => ExecutionResultMerger::merge($results)
            );
    }

    /**
     * @param string $subSchemaName
     * @param OperationDefinitionNode $subOperation
     * @param array<string, FragmentDefinitionNode> $fragments
     * @param array<string, mixed> $variables
     * @return Promise
     * @throws \Exception
     */
    private function delegateToExecute(
        string $subSchemaName,
        OperationDefinitionNode $subOperation,
        array $fragments,
        array $variables
    ): Promise {
        $subSchema = $this->subSchemaRegistry->getSubSchema($subSchemaName);

        return $subSchema->delegator->delegateToExecute($this->executionSchema, $subOperation, $fragments, $variables);
    }

    private function splitSelections(
        SelectionSetNode $selectionSet = null,
        InlineFragmentNode|FragmentSpreadNode $rootFragmentSelection = null,
    ): array {
        $operationType = $this->executionSchema->getOperationType($this->rootOperation->operation);
        $selectionSet ??= $this->rootOperation->selectionSet;
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
                    $this->splitSelections($subSelectionSet, $rootFragmentSelection),
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
     * @param Type $parentType
     * @param SelectionSetNode $selectionSet
     * @param array<string, FragmentDefinitionNode> $fragments
     * @param string $path
     * @return array<string, array{FieldNode, array<string, mixed>, Relation}>
     * @throws Error
     */
    private function collectRelations(
        Type $parentType,
        SelectionSetNode $selectionSet,
        array $fragments,
        string $path = ''
    ): array {
        $relations = [];

        while ($parentType instanceof WrappingType) {
            if ($parentType instanceof ListOfType) {
                $path .= '[]';
            }

            $parentType = $parentType->getWrappedType();
        }

        foreach ($selectionSet->selections as $pos => $selection) {
            $selectionPath = $path;
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
                    $argValues = Values::getArgumentValues($field, $selection, $this->variables);
                    $relations[$subSchema][$operation][$path][] = [$selection, $argValues, $relation];

                    if (!$relation->argResolver instanceof MandatorySelectionSetProviderInterface) {
                        unset($selectionSet->selections[$pos]);

                        continue;
                    }

                    /// Replace it with mandatory fields needed to resolve args.
                    $selectionSet->selections[$pos] = Parser::fragment(
                        sprintf(
                            '... on %s %s',
                            $parentTypename,
                            $relation->argResolver->getMandatorySelectionSet($relation)
                        ),
                        ['noLocation' => true]
                    );
                } else {
                    $type = $field->getType();
                    $subSelectionSet = $selection->selectionSet;

                    if (null !== $subSelectionSet) {
                        /// increase selection path if this field is object
                        $selectionPath = ltrim(
                            sprintf('%s.%s', $selectionPath, $fieldAlias ?? $fieldName),
                            '.'
                        );
                    }
                }
            }

            if (null !== $type && null !== $subSelectionSet) {
                $relations = array_merge_recursive(
                    $relations,
                    $this->collectRelations($type, $subSelectionSet, $fragments, $selectionPath)
                );
            }
        }

        $selectionSet->selections->reindex();

        return $relations;
    }

    /**
     * @param OperationDefinitionNode $operation
     * @param FragmentDefinitionNode[] $fragments
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
            if (isset($this->variables[$name])) {
                $variables[$name] = $this->variables[$name];
            }
        }

        return $variables;
    }

    /**
     * @throws \JsonException
     * @throws Error
     */
    private function resolveRelations(ExecutionResult $result, array $relations): Promise
    {
        if (null === $result->data) {
            return $this->promiseAdapter->createFulfilled($result);
        }

        $promises = [];

        foreach ($relations as $subSchemaName => $subOperations) {
            foreach ($subOperations as $kind => $pathRelationFields) {
                foreach ($pathRelationFields as $path => $relationFields) {
                    $promises[] = $this->delegateRelationQueries(
                        $subSchemaName,
                        $kind,
                        $result->data,
                        $path,
                        $relationFields,
                    );
                }
            }
        }

        return $this
            ->promiseAdapter
            ->all($promises)
            ->then(
                static fn (array $relationResults) => array_filter($relationResults)
            )
            ->then(
                static fn (array $relationResults) => ExecutionResultMerger::mergeWithRelationResults($result, $relationResults)
            );
    }


    /**
     * @throws \JsonException
     * @throws Error
     * @throws \Exception
     */
    private function delegateRelationQueries(
        string $subSchemaName,
        string $operation,
        array &$accessedData,
        string $path,
        array $relationFields,
    ): ?Promise {
        $pos = explode('.', $path, 2);
        $accessPath = $pos[0];
        $isList = false;
        $listDepth = 0;

        while (str_ends_with($accessPath, '[]')) {
            $isList = true;
            $accessPath = substr($accessPath, 0, -2);
            $listDepth++;
        }


        $originalData = $accessedData[$accessPath];
        $data = &$accessedData[$accessPath];

        if (null === $data) {
            /// Give up to access empty object or list
            return null;
        }

        if ($pos[0] !== $path) {
            if (!$isList) {
                return $this->delegateRelationQueries(
                    $subSchemaName,
                    $operation,
                    $data,
                    $pos[1],
                    $relationFields,
                );
            }

            return $this->delegateRelationQueriesList(
                $subSchemaName,
                $operation,
                $data,
                $pos[1],
                $relationFields,
                $listDepth,
            );
        }

        $operation = $this->createOperation($operation);
        $variables = [];

        if ($isList) {
            $variables += $this->addRelationSelectionsToList(
                $operation,
                $data,
                $relationFields,
                $listDepth
            );
        } else {
            $variables += $this->addRelationSelections(
                $operation,
                $data,
                $relationFields
            );
        }

        /// Unlike root operation, all relation selections have the same sub schema,
        /// we can skip logics remove difference sub schema before delegate
        $operationType = $this->executionSchema->getOperationType($operation->operation);
        $fragments = array_map(
            static fn (Node $node) => $node->cloneDeep(),
            array_unique($this->collectFragments($operation->selectionSet))
        );

        $relationsRelations = $this->collectRelations($operationType, $operation->selectionSet, $fragments);
        $variables += $this->collectVariables($operation, $fragments);
        $variableDefinitions = $this->createVariableDefinitions($variables)->merge($operation->variableDefinitions);

        $operation->variableDefinitions = $variableDefinitions;

        return $this
            ->delegateToExecute($subSchemaName, $operation, $fragments, $variables)
            ->then(
                /// Recursive to resolve all relations first
                fn (ExecutionResult $result) => $this->resolveRelations($result, $relationsRelations)
            )
            ->then(
                /// Then merge up resolved data
                function (ExecutionResult $result) use ($isList, $listDepth, $originalData, &$data) {
                    if ([] !== $result->errors) {
                        /// Revert data if have any errors
                        $data = $originalData;

                        return $result;
                    }

                    if (!$isList) {
                        $this->mergeUpRelationsResult($result, $data);
                    } else {
                        $this->mergeUpRelationsResultToList($result, $data, $listDepth);
                    }

                    return $result;
                }
            );
    }

    /**
     * @throws \JsonException
     * @throws Error
     */
    private function delegateRelationQueriesList(
        string $subSchemaName,
        string $operation,
        array &$accessedData,
        string $path,
        array $relationFields,
        int $depth,
    ): Promise {
        if ($depth > 0) {
            $promises = [];

            foreach ($accessedData as &$value) {
                if (null === $value) {
                    continue;
                }

                $promises[] = $this->delegateRelationQueriesList(
                    $subSchemaName,
                    $operation,
                    $value,
                    $path,
                    $relationFields,
                    --$depth,
                );
            }

            return $this
                ->promiseAdapter
                ->all($promises)
                ->then(
                    static fn (array $results) => array_filter($results)
                )
                ->then(
                    static fn (array $results) => ExecutionResultMerger::merge($results)
                );
        }

        return $this->delegateRelationQueries(
            $subSchemaName,
            $operation,
            $accessedData,
            $path,
            $relationFields
        );
    }

    private function addRelationSelectionsToList(
        OperationDefinitionNode $operation,
        array &$objectValueOrList,
        array $relationFields,
        int $depth
    ): array {
        if ($depth > 0) {
            $variables = [];

            foreach ($objectValueOrList as &$value) {
                if (null === $value) {
                    continue;
                }

                $variables += $this->addRelationSelectionsToList($operation, $value, $relationFields, --$depth);
            }

            return $variables;
        }

        return $this->addRelationSelections($operation, $objectValueOrList, $relationFields);
    }

    private function addRelationSelections(
        OperationDefinitionNode $operation,
        array &$objectValue,
        array $relationFields,
    ): array {
        $variables = [];

        foreach ($relationFields as $relationField) {
            /**
             * @var FieldNode $field
             * @var array<string, mixed> $args
             * @var Relation $relation
             */
            [$field, $args, $relation] = $relationField;
            $operationType = $this->executionSchema->getOperationType($relation->operation->value);
            $operationField = $operationType->getField($relation->operationField);
            $selection = $field->cloneDeep();

            $aliasOrName = $selection->alias?->value ?? $selection->name->value;
            $alias = $this->getUid();
            $selection->alias = Parser::name($alias, ['noLocation' => true]);
            $selection->name = Parser::name($relation->operationField, ['noLocation' => true]);
            $selection->arguments = new NodeList([]);
            $arguments = $relation->argResolver->resolve($objectValue, $args, $relation);

            foreach ($operationField->args as $operationFieldArg) {
                if (!array_key_exists($operationFieldArg->name, $arguments)) {
                    continue;
                }

                $varName = $this->getUid();
                $varValue = $arguments[$operationFieldArg->name];
                $variables[$varName] = $varValue;
                $selection->arguments[] = Parser::argument(
                    sprintf('%s: $%s', $operationFieldArg->name, $varName),
                    ['noLocation' => true]
                );
                $operation->variableDefinitions[] = Parser::variableDefinition(
                    sprintf('$%s: %s', $varName, $operationFieldArg->getType()->toString()),
                    ['noLocation' => true]
                );
            }

            $operation->selectionSet->selections[] = $selection;

            /// Value will be replaced after operation executed
            $objectValue[$alias] = $aliasOrName;
        }

        return $variables;
    }

    private function mergeUpRelationsResult(ExecutionResult $result, array &$data): void
    {
        foreach ($result->data as $field => $value) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $alias = $data[$field];
            $data[$alias] = $value;

            unset($data[$field]);
        }
    }

    private function mergeUpRelationsResultToList(ExecutionResult $result, array &$data, int $depth): void
    {
        if ($depth > 0) {
            foreach ($data as &$value) {
                if (null === $value) {
                    continue;
                }

                $this->mergeUpRelationsResultToList($result, $value, --$depth);
            }
        } else {
            $this->mergeUpRelationsResult($result, $data);
        }
    }

    private function createOperation(string $operation): OperationDefinitionNode
    {
        return new OperationDefinitionNode([
            'name' => Parser::name('x_graphql', ['noLocation' => true]),
            'operation' => $operation,
            'directives' => $this->rootOperation->directives->cloneDeep(),
            'selectionSet' => new SelectionSetNode(['selections' => new NodeList([])]),
            'variableDefinitions' => new NodeList([]),
        ]);
    }

    private function createVariableDefinitions(array $variables): NodeList
    {
        $definitions = new NodeList([]);

        foreach ($this->rootOperation->variableDefinitions as $definition) {
            /** @var VariableDefinitionNode $definition */
            $name = $definition->variable->name->value;

            if (array_key_exists($name, $variables)) {
                $definitions[] = $definition->cloneDeep();
            }
        }

        return $definitions;
    }

    private function getUid(): string
    {
        static $pos = 0;

        return sprintf('_%d_', ++$pos);
    }
}
