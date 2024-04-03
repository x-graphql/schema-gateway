<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway;

use GraphQL\Error\Error;
use GraphQL\Error\SerializationError;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use Psr\SimpleCache\InvalidArgumentException;
use XGraphQL\DelegateExecution\ErrorsReporterInterface;
use XGraphQL\DelegateExecution\Execution;
use XGraphQL\SchemaCache\SchemaCache;
use XGraphQL\SchemaGateway\AST\ASTBuilder;
use XGraphQL\SchemaGateway\Execution\ExecutionDelegator;

final class SchemaGatewayFactory
{
    /**
     * @throws Error
     * @throws \ReflectionException
     * @throws InvalidArgumentException
     * @throws \JsonException
     * @throws SerializationError
     */
    public static function create(
        iterable $subSchemas,
        iterable $relations = [],
        SchemaCache $cache = null,
        ErrorsReporterInterface $errorsReporter = null,
    ): Schema {
        $subSchemasRegistry = new SubSchemaRegistry($subSchemas);
        $relationsRegistry = new RelationRegistry($relations);
        $schema = $cache?->load();

        if (null === $schema) {
            $astBuilder = new ASTBuilder($subSchemasRegistry, $relationsRegistry);
            $ast = $astBuilder->build();
            $schema = BuildSchema::buildAST($ast, options: ['assumeValidSDL' => true]);

            $cache?->save($schema);
        }

        $delegator = new ExecutionDelegator($subSchemasRegistry, $relationsRegistry);

        return Execution::delegate($schema, $delegator, $errorsReporter);
    }
}
