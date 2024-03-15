<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Test;

use PHPUnit\Framework\TestCase;
use XGraphQL\SchemaGateway\Exception\InvalidArgumentException;
use XGraphQL\SchemaGateway\Relation;
use XGraphQL\SchemaGateway\RelationArgumentResolverInterface;
use XGraphQL\SchemaGateway\RelationOperation;
use XGraphQL\SchemaGateway\RelationRegistry;

class RelationRegistryTest extends TestCase
{
    public function testConstructor(): void
    {
        $registry = new RelationRegistry([]);

        $this->assertInstanceOf(RelationRegistry::class, $registry);
    }

    public function testHas(): void
    {
        $relation = new Relation(
            'Test',
            'x_graphql',
            RelationOperation::MUTATION,
            'x_mutation',
            $this->createStub(RelationArgumentResolverInterface::class)
        );
        $registry = new RelationRegistry([$relation]);

        $this->assertTrue($registry->hasRelation('Test', 'x_graphql'));
        $this->assertFalse($registry->hasRelation('Unknown', 'x_graphql'));
    }

    public function testGet(): void
    {
        $relation = new Relation(
            'Test',
            'x_graphql',
            RelationOperation::MUTATION,
            'x_mutation',
            $this->createStub(RelationArgumentResolverInterface::class)
        );
        $registry = new RelationRegistry([$relation]);

        $this->assertSame($relation, $registry->getRelation('Test', 'x_graphql'));

        $this->expectException(InvalidArgumentException::class);

        $registry->getRelation('Unknown', 'x_graphql');
    }
}
