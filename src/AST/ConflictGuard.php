<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\AST;

use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\Type;
use XGraphQL\SchemaGateway\Exception\ConflictException;
use XGraphQL\Utils\SchemaPrinter;

final class ConflictGuard
{
    private const PRINT_OPTIONS = [
        'sortArguments' => true,
        'sortEnumValues' => true,
        'sortFields' => true,
        'sortInputFields' => true,
        'sortTypes' => true,
    ];

    public static function directiveGuard(Directive $directive1, Directive $directive2): void
    {
        $printer = (new \ReflectionMethod(SchemaPrinter::class, 'printDirective'))->getClosure();

        natsort($directive1->locations);
        natsort($directive2->locations);

        $sdl1 = $printer($directive1, self::PRINT_OPTIONS);
        $sdl2 = $printer($directive2, self::PRINT_OPTIONS);

        if ($sdl1 !== $sdl2) {
            throw new ConflictException(
                sprintf(
                    'Directive conflict: `%s` with `%s`',
                    $sdl1,
                    $sdl2,
                ),
            );
        }
    }

    public static function typeGuard(Type&NamedType $type1, Type&NamedType $type2): void
    {
        $sdl1 = SchemaPrinter::printType($type1, self::PRINT_OPTIONS);
        $sdl2 = SchemaPrinter::printType($type2, self::PRINT_OPTIONS);

        if ($sdl1 !== $sdl2) {
            throw new ConflictException(sprintf('Type conflict: `%s` with `%s`', $sdl1, $sdl2));
        }
    }
}
