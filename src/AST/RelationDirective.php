<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\AST;

use GraphQL\Language\AST\ArgumentNode;
use GraphQL\Language\AST\DirectiveNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Language\DirectiveLocation;
use XGraphQL\SchemaGateway\Exception\LogicException;

final class RelationDirective
{
    public const NAME = 'relation';

    public static function definition(): string
    {
        return sprintf('directive @%s(operation: String!, field: String!) on %s', self::NAME, DirectiveLocation::FIELD_DEFINITION);
    }

    public static function findOperationField(FieldDefinitionNode $node): ?array
    {
        $argsValues = [];

        foreach ($node->directives as $directive) {
            /** @var DirectiveNode $directive */
            if ($directive->name->value !== self::NAME) {
                continue;
            }

            foreach ($directive->arguments as $arg) {
                /** @var ArgumentNode $arg */
                $name = $arg->name->value;
                $value = $arg->value;

                assert($value instanceof StringValueNode);

                $argsValues[$name] = $value->value;
            }
        }

        if ([] === $argsValues) {
            return null;
        }

        if (!isset($argsValues['operation']) || !isset($argsValues['field'])) {
            throw new LogicException('Both `operation` and `field` arg of directive @relation should be exists');
        }

        return [$argsValues['operation'], $argsValues['field']];
    }
}
