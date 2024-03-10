<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Execution;

use GraphQL\Error\Error;
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
     * @throws Error
     */
    public function delegate(
        Schema $executionSchema,
        OperationDefinitionNode $operation,
        array $fragments = [],
        array $variables = []
    ): Promise {
        $resolver = new Resolver(
            $executionSchema,
            $operation,
            $fragments,
            $variables,
            $this->relationRegistry,
            $this->subSchemaRegistry,
            $this->getPromiseAdapter(),
        );

        return $resolver->resolve();
    }

    public function getPromiseAdapter(): PromiseAdapter
    {
        static $adapter = null;

        if (null !== $adapter) {
            return $adapter;
        }

        foreach ($this->subSchemaRegistry->subSchemas as $subSchema) {
            /// Expect all sub schema should have same promise adapter
            /// so just use adapter of first element
            return $adapter = $subSchema->delegator->getPromiseAdapter();
        }
    }
}
