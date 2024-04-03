<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Test;

use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use XGraphQL\SchemaCache\SchemaCache;
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
        $arrayCache = new ArrayAdapter();
        $schemaCache = new SchemaCache(new Psr16Cache($arrayCache));

        $this->assertEmpty($arrayCache->getValues());

        $schema = SchemaGatewayFactory::create([], cache: $schemaCache);

        $this->assertNotEmpty($arrayCache->getValues());

        $schemaFromCache = SchemaGatewayFactory::create(
            [
                new SubSchema(
                    'test',
                    BuildSchema::build('type Query { name: String! }'),
                )
            ],
            cache: $schemaCache,
        );

        /// new sub schema should not affect cached result
        $this->assertEquals(SchemaPrinter::doPrint($schema), SchemaPrinter::doPrint($schemaFromCache));

        $this->assertTrue($arrayCache->clear());

        $schemaRebuilt = SchemaGatewayFactory::create(
            [
                new SubSchema(
                    'test',
                    BuildSchema::build('type Query { name: String! }'),
                )
            ],
            cache: $schemaCache,
        );

        $this->assertNotEquals(SchemaPrinter::doPrint($schema), SchemaPrinter::doPrint($schemaRebuilt));
    }
}
