<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Execution;

use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Type\Schema;
use XGraphQL\DelegateExecution\ExecutionDelegatorInterface;
use XGraphQL\SchemaGateway\RelationRegistry;
use XGraphQL\SchemaGateway\SubSchemaRegistry;

final readonly class ExecutionDelegator implements ExecutionDelegatorInterface
{
    public function __construct(
        private SubSchemaRegistry $subSchemaRegistry,
        private RelationRegistry $relationRegistry
    ) {
    }

    /**
     * @throws \JsonException
     */
    public function delegate(
        Schema $executionSchema,
        OperationDefinitionNode $operation,
        array $fragments = [],
        array $variables = []
    ): Promise {
        $resolver = new QueryResolver(
            $executionSchema,
            $fragments,
            $variables,
            $operation->variableDefinitions,
            $this->relationRegistry,
            $this->subSchemaRegistry,
            $this->getPromiseAdapter(),
        );

        return $resolver->resolve($operation);
    }

    public function getPromiseAdapter(): PromiseAdapter
    {
        foreach ($this->subSchemaRegistry->subSchemas as $subSchema) {
            return $subSchema->delegator->getPromiseAdapter();
        }
    }
}
