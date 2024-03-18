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

/**
 * @internal
 */
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

        foreach ($this->splitSelections() as $subSchemaName => $selections) {
            $operation = $this->createOperation($this->rootOperation->operation);
            $operation->selectionSet->selections = new NodeList($selections);

            $operationType = $this->executionSchema->getOperationType($operation->operation);
            $fragments = $this->collectFragments($operationType, $operation->selectionSet);
            $relations = $this->collectRelations($operationType, $operation->selectionSet, $fragments);

            /// All selection set is clear, let collects using variables
            $variables = $this->collectVariables($operation, $fragments);

            $operation->variableDefinitions = $this->createVariableDefinitions($variables);

            $promise = $this->delegateToExecute($subSchemaName, $operation, $fragments, $variables);

            $promises[] = $promise->then(
                fn (ExecutionResult $result) => $this->resolveRelations($result, $relations)
            );
        }

        return $this
            ->promiseAdapter
            ->all($promises)
            ->then($this->mergeExecutionResults(...));
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

    private function splitSelections(SelectionSetNode $selectionSet = null): array
    {
        $operationType = $this->executionSchema->getOperationType($this->rootOperation->operation);
        $selectionSet ??= $this->rootOperation->selectionSet;
        $selections = [];

        foreach ($selectionSet->selections as $selection) {
            /** @var FieldNode|FragmentSpreadNode|InlineFragmentNode $selection */

            $subSelectionSet = null;

            if ($selection instanceof FragmentSpreadNode || $selection instanceof InlineFragmentNode) {
                if ($selection instanceof FragmentSpreadNode) {
                    $fragment = $this->fragments[$selection->name->value];
                    $subSelectionSet = $fragment->selectionSet;
                } else {
                    $subSelectionSet = $selection->selectionSet;
                }

                foreach ($this->splitSelections($subSelectionSet) as $subSchema => $subSelections) {
                    $selections[$subSchema][] = new InlineFragmentNode(
                        [
                            'typeCondition' => Parser::namedType($operationType->name()),
                            'selectionSet' => new SelectionSetNode(
                                ['selections' => new NodeList($subSelections)]
                            )
                        ]
                    );
                }
            }

            if ($selection instanceof FieldNode && Introspection::TYPE_NAME_FIELD_NAME !== $selection->name->value) {
                $fieldName = $selection->name->value;
                $field = $operationType->getField($fieldName);
                /// AST of fields of operation type MUST have delegate directive
                $delegateDirective = DelegateDirective::find($field->astNode);
                $subSchema = $delegateDirective->subSchema;
                $selections[$subSchema][] = $selection->cloneDeep();
            }
        }

        return $selections;
    }

    private function collectFragments(
        Type $parentType,
        SelectionSetNode $selectionSet,
        array &$visitedFragments = []
    ): array {
        if ($parentType instanceof WrappingType) {
            $parentType = $parentType->getInnermostType();
        }

        /** @var array<string, FragmentDefinitionNode> $fragments */
        $fragments = [];

        foreach ($selectionSet->selections as $selection) {
            $type = $subSelectionSet = null;

            if ($selection instanceof FragmentSpreadNode) {
                $name = $selection->name->value;

                if (isset($visitedFragments[$name])) {
                    continue;
                }

                $fragment = $fragments[$name] = $this->fragments[$name]->cloneDeep();
                $typename = $fragment->typeCondition->name->value;
                $type = $this->executionSchema->getType($typename);
                $subSelectionSet = $fragment->selectionSet;
                $visitedFragments[$name] = true;
            }

            if ($selection instanceof InlineFragmentNode) {
                $typename = $selection->typeCondition->name->value;
                $type = $this->executionSchema->getType($typename);
                $subSelectionSet = $selection->selectionSet;
            }

            if ($selection instanceof FieldNode) {
                assert($parentType instanceof HasFieldsType);

                $fieldName = $selection->name->value;

                if (Introspection::TYPE_NAME_FIELD_NAME === $fieldName) {
                    continue;
                }

                if ($this->relationRegistry->hasRelation($parentType->name, $fieldName)) {
                    /// Relation fields will be removed, so collect fragments may cause type unknown or fragment unused errors.
                    continue;
                }

                $type = $parentType->getField($fieldName)->getType();
                $subSelectionSet = $selection->selectionSet;
            }

            if (null !== $type && null !== $subSelectionSet) {
                $fragments += $this->collectFragments($type, $subSelectionSet, $visitedFragments);
            }
        }

        return $fragments;
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
                $fieldAlias = $selection->alias?->value ?? $fieldName;
                $field = $parentType->getField($fieldName);

                if ($this->relationRegistry->hasRelation($parentTypename, $fieldName)) {
                    /// Expect relation field must have delegate directive
                    $delegateDirective = DelegateDirective::find($field->astNode);
                    $subSchema = $delegateDirective->subSchema;
                    $operation = $delegateDirective->operation;
                    $relation = $this->relationRegistry->getRelation($parentTypename, $fieldName);
                    $argValues = Values::getArgumentValues($field, $selection, $this->variables);
                    $relations[$subSchema][$operation][$path][$fieldAlias][] = [$selection, $argValues, $relation];

                    unset($selectionSet->selections[$pos]);

                    if ($relation->argResolver instanceof MandatorySelectionSetProviderInterface) {
                        /// Replace it with mandatory fields needed to resolve args.
                        $selectionSet->selections[$pos] = Parser::fragment(
                            sprintf(
                                '... on %s %s',
                                $parentTypename,
                                $relation->argResolver->getMandatorySelectionSet($relation)
                            ),
                            ['noLocation' => true]
                        );
                    }

                    continue;
                }

                $type = $field->getType();
                $subSelectionSet = $selection->selectionSet;

                if (null !== $subSelectionSet) {
                    /// increase selection path if this field is object
                    $selectionPath = ltrim(
                        sprintf('%s.%s', $selectionPath, $fieldAlias),
                        '.'
                    );
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
                fn (array $relationResults) => $this->mergeErrorsAndExtensionFromRelationResults(
                    $result,
                    $relationResults
                )
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

        $operationType = $this->executionSchema->getOperationType($operation->operation);
        $fragments = $this->collectFragments($operationType, $operation->selectionSet);
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
                ->then($this->mergeExecutionResults(...));
        }

        return $this->delegateRelationQueries(
            $subSchemaName,
            $operation,
            $accessedData,
            $path,
            $relationFields
        );
    }

    /**
     * @throws \JsonException
     */
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

    /**
     * @throws \JsonException
     */
    private function addRelationSelections(
        OperationDefinitionNode $operation,
        array &$objectValue,
        array $relationFields,
    ): array {
        $variables = [];

        foreach ($relationFields as $fieldAlias => $infos) {
            $alias = $this->getUid();

            /// Value will be replaced after operation executed
            $objectValue[$alias] = $fieldAlias;

            /**
             * @var array<string, mixed> $args
             * @var Relation $relation
             */
            [, $currentArgs, $relation] = $infos[0]; /// All field selection MUST be the same args and relation.

            $args = $relation->argResolver->resolve($objectValue, $currentArgs, $relation);
            $operationType = $this->executionSchema->getOperationType($relation->operation->value);
            $operationField = $operationType->getField($relation->operationField);
            $selectionArgs = new NodeList([]);

            foreach ($operationField->args as $fieldArg) {
                if (!array_key_exists($fieldArg->name, $args)) {
                    continue;
                }

                $varName = $this->getUid();
                $varValue = $args[$fieldArg->name];
                $variables[$varName] = $varValue;
                $selectionArgs[] = Parser::argument(
                    sprintf('%s: $%s', $fieldArg->name, $varName),
                    ['noLocation' => true]
                );
                $operation->variableDefinitions[] = Parser::variableDefinition(
                    sprintf('$%s: %s', $varName, $fieldArg->getType()->toString()),
                    ['noLocation' => true]
                );
            }

            $useInlineFragment = false;

            foreach ($infos as $info) {
                /** @var FieldNode $field */
                [$field,] = $info;

                $selection = $field->cloneDeep();

                $selection->alias = Parser::name($alias, ['noLocation' => true]);
                $selection->name = Parser::name($relation->operationField, ['noLocation' => true]);
                $selection->arguments = $selectionArgs;

                if (!$useInlineFragment) {
                    $operation->selectionSet->selections[] = $selection;
                } else {
                    /// use inline fragment for resolving multi field nodes
                    $operation->selectionSet->selections[] = new InlineFragmentNode(
                        [
                            'typeCondition' => Parser::namedType($operationType->name()),
                            'selectionSet' => new SelectionSetNode(
                                ['selections' => new NodeList([$selection])]
                            )
                        ]
                    );
                }

                $useInlineFragment = true;
            }
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

    private function mergeExecutionResults(array $results): ExecutionResult
    {
        $data = [];
        $errors = [];
        $extensions = [];

        foreach ($results as $result) {
            $data += $result->data ?? [];
            $extensions = array_merge($extensions, $result->extensions ?? []);
            $errors = array_merge($errors, $result->errors);
        }

        return new ExecutionResult(
            [] !== $data ? $data : null,
            $errors,
            $extensions,
        );
    }

    /**
     * @param ExecutionResult $result
     * @param ExecutionResult[][]|ExecutionResult[] $relationResults
     * @return ExecutionResult
     */
    private function mergeErrorsAndExtensionFromRelationResults(
        ExecutionResult $result,
        array $relationResults
    ): ExecutionResult {
        foreach ($relationResults as $relationResult) {
            $result->extensions = array_merge(
                $result->extensions ?? [],
                $relationResult->extensions ?? []
            );

            foreach ($relationResult->errors as $error) {
                $result->errors[] = new Error(
                    $error->getMessage(),
                    previous: $error,
                    extensions: array_merge(
                        [
                            'x_graphql' => [
                                'code' => 'relation_error'
                            ]
                        ],
                        $error->getExtensions() ?? [],
                    ),
                );
            }
        }

        return $result;
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
        static $uid = null;
        static $pos = 0;
        $uid ??= uniqid('_');

        return sprintf('%s_%d', $uid, ++$pos);
    }
}
