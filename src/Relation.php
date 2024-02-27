<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway;

final readonly class Relation
{
    /**
     * @param string $onType
     * @param string $field
     * @param RelationOperation $operation
     * @param string $operationField
     * @param RelationArgumentResolverInterface $argResolver
     */
    public function __construct(
        public string $onType,
        public string $field,
        public RelationOperation $operation,
        public string $operationField,
        public RelationArgumentResolverInterface $argResolver,
    ) {
    }
}
