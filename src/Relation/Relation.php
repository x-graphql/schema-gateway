<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Relation;

final readonly class Relation
{
    /**
     * @param string $onType
     * @param string $field
     * @param OperationType $operationType
     * @param string $operationField
     * @param ArgumentResolverInterface $argResolver
     */
    public function __construct(
        public string $onType,
        public string $field,
        public OperationType $operationType,
        public string $operationField,
        public ArgumentResolverInterface $argResolver,
    ) {
    }
}
