<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Test;

use PHPUnit\Framework\TestCase;
use XGraphQL\Delegate\SchemaDelegatorInterface;
use XGraphQL\SchemaGateway\Exception\InvalidArgumentException;
use XGraphQL\SchemaGateway\SubSchema;
use XGraphQL\SchemaGateway\SubSchemaRegistry;

class SubSchemaRegistryTest extends TestCase
{
    public function testConstructor(): void
    {
        $registry = new SubSchemaRegistry([]);

        $this->assertInstanceOf(SubSchemaRegistry::class, $registry);
    }

    public function testHas(): void
    {
        $subSchema = new SubSchema('a', $this->createStub(SchemaDelegatorInterface::class));
        $registry = new SubSchemaRegistry([$subSchema]);

        $this->assertTrue($registry->hasSubSchema('a'));
        $this->assertFalse($registry->hasSubSchema('b'));
    }

    public function testGet(): void
    {
        $subSchema = new SubSchema('a', $this->createStub(SchemaDelegatorInterface::class));
        $registry = new SubSchemaRegistry([$subSchema]);

        $this->assertSame($subSchema, $registry->getSubSchema('a'));

        $this->expectException(InvalidArgumentException::class);

        $registry->getSubSchema('b');
    }
}
