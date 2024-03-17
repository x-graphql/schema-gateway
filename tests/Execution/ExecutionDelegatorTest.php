<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Test\Execution;

use GraphQL\Executor\Executor;
use GraphQL\Type\Schema;
use PHPUnit\Framework\TestCase;
use XGraphQL\Delegate\SchemaDelegator;
use XGraphQL\SchemaGateway\Execution\ExecutionDelegator;
use XGraphQL\SchemaGateway\RelationRegistry;
use XGraphQL\SchemaGateway\SubSchema;
use XGraphQL\SchemaGateway\SubSchemaRegistry;

class ExecutionDelegatorTest extends TestCase
{
    public function testConstructor(): void
    {
        $delegator = new ExecutionDelegator(new SubSchemaRegistry([]), new RelationRegistry([]));

        $this->assertInstanceOf(ExecutionDelegator::class, $delegator);
    }

    public function testGetPromiseAdapterReturnAdapterOfFirstSubSchema(): void
    {
        $schemaDelegator = new SchemaDelegator(new Schema([]));
        $executionDelegator = new ExecutionDelegator(
            new SubSchemaRegistry(
                [
                    new SubSchema('a', $schemaDelegator),
                ],
            ),
            new RelationRegistry([]),
        );

        $this->assertSame($schemaDelegator->getPromiseAdapter(), $executionDelegator->getPromiseAdapter());
        $this->assertSame(Executor::getPromiseAdapter(), $executionDelegator->getPromiseAdapter());
    }
}
