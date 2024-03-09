<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway;

use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use Psr\SimpleCache\CacheInterface;
use XGraphQL\DelegateExecution\Execution;
use XGraphQL\SchemaGateway\AST\ASTBuilder;
use XGraphQL\SchemaGateway\Execution\ExecutionDelegator;

final class SchemaGatewayFactory
{
    public static function create(
        iterable $subSchemas,
        iterable $relations = [],
        CacheInterface $astCache = null
    ): Schema {
        $subSchemasRegistry = new SubSchemaRegistry($subSchemas);
        $relationsRegistry = new RelationRegistry($relations);

        $astBuilder = new ASTBuilder($subSchemasRegistry, $relationsRegistry);
        $ast = $astBuilder->build();

        $schema = BuildSchema::buildAST($ast, options: ['assumeValidSDL' => true]);

        $delegator = new ExecutionDelegator($subSchemasRegistry, $relationsRegistry);

        Execution::delegate($schema, $delegator);

        return $schema;
    }
}
