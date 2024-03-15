<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\AST;

use GraphQL\Language\AST\ArgumentNode;
use GraphQL\Language\AST\DirectiveNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Language\DirectiveLocation;

/**
 * @internal
 */
final readonly class DelegateDirective
{
    public const NAME = 'delegate';

    public function __construct(public string $subSchema, public string $operation, public string $operationField)
    {
    }

    public static function definition(): string
    {
        return sprintf(
            'directive @%s(subSchema: String!, operation: String!, operationField: String!) on %s',
            self::NAME,
            DirectiveLocation::FIELD_DEFINITION
        );
    }

    public static function find(FieldDefinitionNode $node): ?self
    {
        foreach ($node->directives as $directive) {
            /** @var DirectiveNode $directive */
            if ($directive->name->value !== self::NAME) {
                continue;
            }

            $argsValues = [];

            foreach ($directive->arguments as $arg) {
                /** @var ArgumentNode $arg */
                $name = $arg->name->value;
                $value = $arg->value;

                assert($value instanceof StringValueNode);

                $argsValues[$name] = $value->value;
            }

            return new self(...$argsValues);
        }

        return null;
    }
}
