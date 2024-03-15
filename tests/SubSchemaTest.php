<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Test;

use GraphQL\Type\Schema;
use PHPUnit\Framework\TestCase;
use XGraphQL\Delegate\SchemaDelegator;
use XGraphQL\Delegate\SchemaDelegatorInterface;
use XGraphQL\SchemaGateway\SubSchema;

class SubSchemaTest extends TestCase
{
    public function testConstructor(): void
    {
        $schema = $this->createStub(Schema::class);
        $delegator = new SchemaDelegator($schema);
        $withSchema = new SubSchema('a', $schema);
        $withDelegator = new SubSchema('b', $delegator);

        $this->assertEquals('a', $withSchema->name);
        $this->assertEquals('b', $withDelegator->name);
        $this->assertInstanceOf(SchemaDelegatorInterface::class, $withSchema->delegator);
        $this->assertSame($schema, $withSchema->delegator->getSchema());
        $this->assertNotSame($delegator, $withSchema->delegator);
        $this->assertSame($delegator, $withDelegator->delegator);
        $this->assertSame($schema, $withDelegator->delegator->getSchema());
    }

}
