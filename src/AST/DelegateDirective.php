<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\AST;

use GraphQL\Language\AST\ArgumentNode;
use GraphQL\Language\AST\DirectiveNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Language\DirectiveLocation;

final class DelegateDirective
{
    public const NAME = 'delegate';

    public static function definition(): string
    {
        return sprintf('directive @%s(subSchema: String!) on %s', self::NAME, DirectiveLocation::FIELD_DEFINITION);
    }

    public static function findSubSchema(FieldDefinitionNode $node): ?string
    {
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

                if ($name === 'subSchema') {
                    return $value->value;
                }
            }
        }

        return null;
    }
}
