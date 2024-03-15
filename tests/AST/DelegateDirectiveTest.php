<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Test\AST;

use GraphQL\Language\Parser;
use PHPUnit\Framework\TestCase;
use XGraphQL\SchemaGateway\AST\DelegateDirective;

class DelegateDirectiveTest extends TestCase
{
    public function testDefinition(): void
    {
        $expecting = 'directive @delegate(subSchema: String!, operation: String!, operationField: String!) on FIELD_DEFINITION';

        $this->assertEquals($expecting, DelegateDirective::definition());
    }

    public function testFound(): void
    {
        $ast = Parser::fieldDefinition(
            <<<'SDL'
a: String! @awesome @delegate(subSchema: "a", operation: "b", operationField: "c")
SDL
        );
        $directive = DelegateDirective::find($ast);

        $this->assertInstanceOf(DelegateDirective::class, $directive);
        $this->assertEquals('a', $directive->subSchema);
        $this->assertEquals('b', $directive->operation);
        $this->assertEquals('c', $directive->operationField);
    }

    public function testNotFound(): void
    {
        $ast = Parser::fieldDefinition(
            <<<'SDL'
a: String!
SDL
        );
        $directive = DelegateDirective::find($ast);

        $this->assertNull($directive);
    }
}
