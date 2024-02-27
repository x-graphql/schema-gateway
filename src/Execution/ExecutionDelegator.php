<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Execution;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Type\Schema;
use XGraphQL\DelegateExecution\ExecutionDelegatorInterface;
use XGraphQL\SchemaGateway\Exception\InvalidArgumentException;
use XGraphQL\SchemaGateway\Relation;
use XGraphQL\SchemaGateway\SubSchema;

final readonly class ExecutionDelegator implements ExecutionDelegatorInterface
{
    /**
     * @param SubSchema[] $subSchemas
     * @param Relation[] $relations
     */
    public function __construct(private iterable $subSchemas, private iterable $relations)
    {
    }

    public function delegate(
        Schema $executionSchema,
        OperationDefinitionNode $operation,
        array $fragments = [],
        array $variables = []
    ): Promise {
        $querySplitter = new QuerySplitter(
            $this->relations,
            $executionSchema,
            $operation,
            $fragments,
            $variables,
        );
        $promises = [];

        foreach ($querySplitter->split() as $subQuery) {
            $subSchema = $this->getSubSchemaByName($subQuery->subSchemaName);
            $promises[] = $subSchema->delegator->delegate(
                $executionSchema,
                $subQuery->operation,
                $subQuery->fragments,
                $subQuery->variables
            );
        }

        return $this
            ->getPromiseAdapter()
            ->all($promises)
            ->then($this->mergeResults(...));
    }

    public function getPromiseAdapter(): PromiseAdapter
    {
        foreach ($this->subSchemas as $subSchema) {
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

    private function getSubSchemaByName(string $name): SubSchema
    {
        foreach ($this->subSchemas as $subSchema) {
            if ($subSchema->name === $name) {
                return $subSchema;
            }
        }

        throw new InvalidArgumentException(sprintf('Sub schema `%s` does not exists', $name));
    }
}
