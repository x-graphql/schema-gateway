<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway;

use GraphQL\Type\Schema;
use XGraphQL\DelegateExecution\SchemaExecutionDelegator;
use XGraphQL\DelegateExecution\SchemaExecutionDelegatorInterface;

final readonly class SubSchema
{
    public SchemaExecutionDelegatorInterface $delegator;

    public function __construct(public string $name, SchemaExecutionDelegatorInterface|Schema $schemaOrDelegator)
    {
        if ($schemaOrDelegator instanceof SchemaExecutionDelegatorInterface) {
            $this->delegator = $schemaOrDelegator;
        } else {
            $this->delegator = new SchemaExecutionDelegator($schemaOrDelegator);
        }
    }
}
