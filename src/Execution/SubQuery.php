<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Execution;

use GraphQL\Language\AST\OperationDefinitionNode;

final readonly class SubQuery
{
    public function __construct(
        public OperationDefinitionNode $operation,
        public array $fragments,
        public array $variables,
        public string $subSchemaName,
        public \ArrayObject $relationFields,
    ) {
    }
}
