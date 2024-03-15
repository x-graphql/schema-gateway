<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway;

use GraphQL\Type\Schema;
use XGraphQL\Delegate\SchemaDelegator;
use XGraphQL\Delegate\SchemaDelegatorInterface;

final readonly class SubSchema
{
    public SchemaDelegatorInterface $delegator;

    public function __construct(public string $name, SchemaDelegatorInterface|Schema $schemaOrDelegator)
    {
        if ($schemaOrDelegator instanceof SchemaDelegatorInterface) {
            $this->delegator = $schemaOrDelegator;
        } else {
            $this->delegator = new SchemaDelegator($schemaOrDelegator);
        }
    }
}
