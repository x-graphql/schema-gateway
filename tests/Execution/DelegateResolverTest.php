<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Test\Execution;

use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Type\Schema;
use PHPUnit\Framework\TestCase;
use XGraphQL\SchemaGateway\Execution\DelegateResolver;
use XGraphQL\SchemaGateway\RelationRegistry;
use XGraphQL\SchemaGateway\SubSchemaRegistry;

class DelegateResolverTest extends TestCase
{
    public function testConstructor(): void
    {
        $resolver = new DelegateResolver(
            $this->createStub(Schema::class),
            $this->createStub(OperationDefinitionNode::class),
            [],
            [],
            new RelationRegistry([]),
            new SubSchemaRegistry([]),
            new SyncPromiseAdapter(),
        );

        $this->assertInstanceOf(DelegateResolver::class, $resolver);
    }
}
