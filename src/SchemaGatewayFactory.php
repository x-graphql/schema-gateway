<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway;

use GraphQL\Error\Error;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\BuildSchema;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use XGraphQL\DelegateExecution\Execution;
use XGraphQL\SchemaGateway\AST\ASTBuilder;
use XGraphQL\SchemaGateway\Execution\ExecutionDelegator;

final class SchemaGatewayFactory
{
    public const CACHE_KEY = '_x_graphql_ast_schema_gateway';

    /**
     * @throws Error
     * @throws \ReflectionException
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    public static function create(
        iterable $subSchemas,
        iterable $relations = [],
        CacheInterface $cache = null
    ): Schema {
        $subSchemasRegistry = new SubSchemaRegistry($subSchemas);
        $relationsRegistry = new RelationRegistry($relations);

        if (!$cache?->has(self::CACHE_KEY)) {
            $astBuilder = new ASTBuilder($subSchemasRegistry, $relationsRegistry);
            $ast = $astBuilder->build();
            $astNormalized = AST::toArray($ast);

            $cache?->set(self::CACHE_KEY, $astNormalized);
        } else {
            $astNormalized = $cache->get(self::CACHE_KEY);
            $ast = AST::fromArray($astNormalized);
        }

        $schema = BuildSchema::buildAST($ast, options: ['assumeValidSDL' => true]);

        $delegator = new ExecutionDelegator($subSchemasRegistry, $relationsRegistry);

        Execution::delegate($schema, $delegator);

        return $schema;
    }
}
