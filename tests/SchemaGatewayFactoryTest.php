<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Test;

use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use XGraphQL\SchemaGateway\SchemaGatewayFactory;
use XGraphQL\SchemaGateway\SubSchema;
use XGraphQL\Utils\SchemaPrinter;

class SchemaGatewayFactoryTest extends TestCase
{
    public function testCreateWithoutCache()
    {
        $schema = SchemaGatewayFactory::create([]);

        $this->assertInstanceOf(Schema::class, $schema);
    }

    public function testCreateWithCache()
    {
        $arrayAdapter = new ArrayAdapter();
        $psr16 = new Psr16Cache($arrayAdapter);

        $this->assertFalse($psr16->has(SchemaGatewayFactory::CACHE_KEY));

        $schema = SchemaGatewayFactory::create([], cache: $psr16);

        $this->assertTrue($psr16->has(SchemaGatewayFactory::CACHE_KEY));

        $schemaCached = SchemaGatewayFactory::create(
            [
                new SubSchema(
                    'test',
                    BuildSchema::build('type Query { name: String! }'),
                )
            ],
            cache: $psr16,
        );

        /// new sub schema should not affect cached result
        $this->assertEquals(SchemaPrinter::doPrint($schema), SchemaPrinter::doPrint($schemaCached));

        $this->assertTrue($psr16->clear());

        $schemaRebuilt = SchemaGatewayFactory::create(
            [
                new SubSchema(
                    'test',
                    BuildSchema::build('type Query { name: String! }'),
                )
            ],
            cache: $psr16,
        );

        $this->assertNotEquals(SchemaPrinter::doPrint($schema), SchemaPrinter::doPrint($schemaRebuilt));
    }
}
