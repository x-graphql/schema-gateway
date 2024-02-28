<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Execution;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Printer;
use GraphQL\Type\Schema;
use XGraphQL\DelegateExecution\ExecutionDelegatorInterface;
use XGraphQL\SchemaGateway\Exception\InvalidArgumentException;
use XGraphQL\SchemaGateway\Relation;
use XGraphQL\SchemaGateway\RelationRegistry;
use XGraphQL\SchemaGateway\SubSchema;
use XGraphQL\SchemaGateway\SubSchemaRegistry;

final readonly class ExecutionDelegator implements ExecutionDelegatorInterface
{
    public function __construct(
        private SubSchemaRegistry $subSchemaRegistry,
        private RelationRegistry $relationRegistry
    ) {
    }

    public function delegate(
        Schema $executionSchema,
        OperationDefinitionNode $operation,
        array $fragments = [],
        array $variables = []
    ): Promise {
        $operationType = $executionSchema->getOperationType($operation->operation);
        $operationSelectionSet = $operation->getSelectionSet();
        $querySplitter = new QuerySplitter($executionSchema, $operation, $fragments, $variables, $this->relationRegistry);

        $promises = [];

        foreach ($querySplitter->split($operationType, $operationSelectionSet) as $subQuery) {
            $subSchema = $this->subSchemaRegistry->getSubSchema($subQuery->subSchemaName);
            $promises[] = $subSchema
                ->delegator
                ->delegate(
                    $executionSchema,
                    $subQuery->operation,
                    $subQuery->fragments,
                    $subQuery->variables
                )
                ->then(fn(ExecutionResult $result) => $this->delegateSubQueries($result, $subQuery));
        }

        return $this
            ->getPromiseAdapter()
            ->all($promises)
            ->then($this->mergeResults(...));
    }

    public function getPromiseAdapter(): PromiseAdapter
    {
        foreach ($this->subSchemaRegistry->subSchemas as $subSchema) {
            return $subSchema->delegator->getPromiseAdapter();
        }
    }

    /**
     * @param ExecutionResult[] $results
     */
    private function mergeResults(iterable $results): ExecutionResult
    {
        $data = [];
        $errors = [];
        $extensions = [];

        foreach ($results as $result) {
            if (null !== $result->data) {
                $data = array_merge($data, $result->data);
            }

            if (null !== $result->extensions) {
                $extensions = array_merge($extensions, $result->extensions);
            }

            $errors = array_merge($errors, $result->errors);
        }

        return new ExecutionResult(
            [] !== $data ? $data : null,
            $errors,
            [] !== $extensions ? $extensions : null,
        );
    }

    private function delegateSubQueries(ExecutionResult $result, SubQuery $query)
    {
    }
}
