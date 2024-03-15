<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Test;

use PHPUnit\Framework\TestCase;
use XGraphQL\SchemaGateway\Relation;
use XGraphQL\SchemaGateway\RelationArgumentResolverInterface;
use XGraphQL\SchemaGateway\RelationOperation;

class RelationTest extends TestCase
{
    public function testConstructor(): void
    {
        $argResolver = $this->createStub(RelationArgumentResolverInterface::class);
        $relation = new Relation(
            'Test',
            'x_graphql',
            RelationOperation::MUTATION,
            'x_mutation',
            $argResolver
        );

        $this->assertEquals('Test', $relation->onType);
        $this->assertEquals('x_graphql', $relation->field);
        $this->assertEquals(RelationOperation::MUTATION, $relation->operation);
        $this->assertEquals('x_mutation', $relation->operationField);
        $this->assertEquals($argResolver, $relation->argResolver);
    }
}
