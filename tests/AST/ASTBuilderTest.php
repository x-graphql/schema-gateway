<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Test\AST;

use GraphQL\Language\Printer;
use GraphQL\Utils\BuildSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XGraphQL\SchemaGateway\AST\ASTBuilder;
use XGraphQL\SchemaGateway\Exception\ConflictException;
use XGraphQL\SchemaGateway\Exception\LogicException;
use XGraphQL\SchemaGateway\Exception\RuntimeException;
use XGraphQL\SchemaGateway\Relation;
use XGraphQL\SchemaGateway\RelationArgumentResolverInterface;
use XGraphQL\SchemaGateway\RelationOperation;
use XGraphQL\SchemaGateway\RelationRegistry;
use XGraphQL\SchemaGateway\SubSchema;
use XGraphQL\SchemaGateway\SubSchemaRegistry;
use XGraphQL\Utils\SchemaPrinter;

class ASTBuilderTest extends TestCase
{
    public function testConstructor(): void
    {
        $builder = new ASTBuilder(new SubSchemaRegistry([]), new RelationRegistry([]));

        $this->assertInstanceOf(ASTBuilder::class, $builder);
    }

    public function testBuild(): void
    {
        $schema1 = BuildSchema::build(
            <<<'SDL'
directive @a on QUERY

type Query {
  dummy1: Dummy!
}

type Dummy {
  test: String!
}

SDL
        );
        $schema2 = BuildSchema::build(
            <<<'SDL'
directive @a on QUERY
directive @b on QUERY

type Query {
  dummy2: String!
}
SDL
        );

        $schema3 = BuildSchema::build(
            <<<'SDL'
directive @b on QUERY

type Query {
  dummy3: Boolean!
}
SDL
        );
        $builder = new ASTBuilder(
            new SubSchemaRegistry(
                [
                    new SubSchema('a', $schema1),
                    new SubSchema('b', $schema2),
                    new SubSchema('c', $schema3),
                ],
            ),
            new RelationRegistry(
                [
                    new Relation(
                        'Dummy',
                        'recursive',
                        RelationOperation::QUERY,
                        'dummy1',
                        $this->createMock(RelationArgumentResolverInterface::class),
                    )
                ]
            ),
        );

        $ast = $builder->build();
        $expectingSDL = <<<'SDL'
directive @a on QUERY

directive @b on QUERY

directive @delegate(subSchema: String!, operation: String!, operationField: String!) on FIELD_DEFINITION

type Query {
  dummy1: Dummy!
  dummy2: String!
  dummy3: Boolean!
}

type Dummy {
  test: String!
  recursive: Dummy!
}

SDL;
        $this->assertEquals($expectingSDL, SchemaPrinter::doPrint(BuildSchema::buildAST($ast)));
    }

    public function testConflictDirectives(): void
    {
        $schema1 = BuildSchema::build(
            <<<'SDL'
directive @a on FIELD

type Query {
  dummy: String!
}
SDL
        );
        $schema2 = BuildSchema::build(
            <<<'SDL'
directive @a on FIELD

type Query {
  dummy: String!
}
SDL
        );
        $schema3 = BuildSchema::build(
            <<<'SDL'
directive @a on QUERY

type Query {
  dummy: String!
}
SDL
        );
        $builder = new ASTBuilder(
            new SubSchemaRegistry(
                [
                    new SubSchema('a', $schema1),
                    new SubSchema('b', $schema2),
                    new SubSchema('c', $schema3),
                ],
            ),
            new RelationRegistry([]),
        );

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('Directive conflict');

        $builder->build();
    }

    public function testDuplicateRootFields(): void
    {
        $schema1 = BuildSchema::build(
            <<<'SDL'
type Query {
  field_duplicate: String!
}
SDL
        );

        $schema2 = BuildSchema::build(
            <<<'SDL'
type Query {
  dummy: String!
}
SDL
        );

        $schema3 = BuildSchema::build(
            <<<'SDL'
type Query {
  field_duplicate: String!
}
SDL
        );
        $builder = new ASTBuilder(
            new SubSchemaRegistry(
                [
                    new SubSchema('a', $schema1),
                    new SubSchema('b', $schema2),
                    new SubSchema('c', $schema3),
                ],
            ),
            new RelationRegistry([]),
        );

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('Duplicated field');

        $builder->build();
    }

    public function testConflictTypes(): void
    {
        $schema1 = BuildSchema::build(
            <<<'SDL'
directive @a on QUERY

type Query {
  dummy1: String!
}

type Conflict {
  test: String!
}

SDL
        );
        $schema2 = BuildSchema::build(
            <<<'SDL'
type Query {
  dummy2: String!
}

type Conflict {
  test: String!
}

SDL
        );

        $schema3 = BuildSchema::build(
            <<<'SDL'
directive @a on QUERY

type Query {
  dummy3: String!
}

scalar Conflict

SDL
        );
        $builder = new ASTBuilder(
            new SubSchemaRegistry(
                [
                    new SubSchema('a', $schema1),
                    new SubSchema('b', $schema2),
                    new SubSchema('c', $schema3),
                ],
            ),
            new RelationRegistry([]),
        );

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('Type conflict');

        $builder->build();
    }

    public function testAddRelationOnUnknownType(): void
    {
        $schema1 = BuildSchema::build(
            <<<'SDL'
directive @a on QUERY

type Query {
  dummy1: String!
}

type Dummy {
  test: String!
}

SDL
        );
        $schema2 = BuildSchema::build(
            <<<'SDL'
type Query {
  dummy2: String!
}

type Dummy {
  test: String!
}

SDL
        );

        $builder = new ASTBuilder(
            new SubSchemaRegistry(
                [
                    new SubSchema('a', $schema1),
                    new SubSchema('b', $schema2),
                ],
            ),
            new RelationRegistry([
                new Relation(
                    'Unknown',
                    'dummy',
                    RelationOperation::QUERY,
                    'dummy1',
                    $this->createMock(RelationArgumentResolverInterface::class),
                ),
            ]),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Type `Unknown` and operation `query` should be exists on schema');

        $builder->build();
    }

    public function testAddRelationOnTypeIsNotObject(): void
    {
        $schema1 = BuildSchema::build(
            <<<'SDL'
directive @a on QUERY

type Query {
  dummy1: String!
}

type Dummy {
  test: String!
}

SDL
        );
        $schema2 = BuildSchema::build(
            <<<'SDL'
type Query {
  dummy2: String!
}

type Dummy {
  test: String!
}

SDL
        );

        $builder = new ASTBuilder(
            new SubSchemaRegistry(
                [
                    new SubSchema('a', $schema1),
                    new SubSchema('b', $schema2),
                ],
            ),
            new RelationRegistry([
                new Relation(
                    'String',
                    'dummy',
                    RelationOperation::QUERY,
                    'dummy1',
                    $this->createMock(RelationArgumentResolverInterface::class),
                ),
            ]),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only support to add relation between object types');

        $builder->build();
    }

    public function testAddRelationWithUnknownOperationField(): void
    {
        $schema1 = BuildSchema::build(
            <<<'SDL'
directive @a on QUERY

type Query {
  dummy1: Dummy!
}

type Dummy {
  test: String!
}

SDL
        );
        $schema2 = BuildSchema::build(
            <<<'SDL'
type Query {
  dummy2: String!
}
SDL
        );

        $builder = new ASTBuilder(
            new SubSchemaRegistry(
                [
                    new SubSchema('a', $schema1),
                    new SubSchema('b', $schema2),
                ],
            ),
            new RelationRegistry([
                new Relation(
                    'Dummy',
                    'unknown',
                    RelationOperation::QUERY,
                    'unknown',
                    $this->createMock(RelationArgumentResolverInterface::class),
                ),
            ]),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Not found field name `unknown` on operation type `Query`');

        $builder->build();
    }

    public function testAddDuplicateRelationFields(): void
    {
        $schema1 = BuildSchema::build(
            <<<'SDL'
directive @a on QUERY

type Query {
  dummy1: Dummy!
}

type Dummy {
  test: String!
}

SDL
        );
        $schema2 = BuildSchema::build(
            <<<'SDL'
type Query {
  dummy2: String!
}
SDL
        );

        $builder = new ASTBuilder(
            new SubSchemaRegistry(
                [
                    new SubSchema('a', $schema1),
                    new SubSchema('b', $schema2),
                ],
            ),
            new RelationRegistry([
                new Relation(
                    'Dummy',
                    'duplicate',
                    RelationOperation::QUERY,
                    'dummy1',
                    $this->createMock(RelationArgumentResolverInterface::class),
                ),
                new Relation(
                    'Dummy',
                    'duplicate',
                    RelationOperation::QUERY,
                    'dummy2',
                    $this->createMock(RelationArgumentResolverInterface::class),
                ),
            ]),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Duplicated relation field `duplicate` on type `Dummy`');

        $builder->build();
    }

    #[DataProvider('operationsProvider')]
    public function testAddRelationOnOperationType(string $operation): void
    {
        $schema1 = BuildSchema::build(
            <<<'SDL'
directive @a on QUERY

type Query {
  dummy1: Boolean!
}
SDL
        );
        $schema2 = BuildSchema::build(
            <<<'SDL'
type Query {
  dummy2: String!
}
SDL
        );

        $builder = new ASTBuilder(
            new SubSchemaRegistry(
                [
                    new SubSchema('a', $schema1),
                    new SubSchema('b', $schema2),
                ],
            ),
            new RelationRegistry([
                new Relation(
                    $operation,
                    'dummy',
                    RelationOperation::QUERY,
                    'dummy1',
                    $this->createMock(RelationArgumentResolverInterface::class),
                ),
            ]),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            sprintf('Add relations on `%s` operation type are not supported!', $operation)
        );

        $builder->build();
    }

    public static function operationsProvider(): array
    {
        return [
            'query' => ['Query'],
            'mutation' => ['Mutation'],
            'subscription' => ['Subscription'],
        ];
    }

    public function testAddRelationWithAndWithoutArgs(): void
    {
        $schema1 = BuildSchema::build(
            <<<'SDL'
type Query {
  dummy1(id: ID!): Dummy!
}

type Dummy {
  id: ID!
}
SDL
        );
        $schema2 = BuildSchema::build(
            <<<'SDL'
type Query {
  dummy2: String!
}
SDL
        );
        $resolver = $this->createMock(RelationArgumentResolverInterface::class);
        /// First time keep, second time ignore
        $resolver->method('shouldKeep')->willReturn(true, false);

        $builder = new ASTBuilder(
            new SubSchemaRegistry(
                [
                    new SubSchema('a', $schema1),
                    new SubSchema('b', $schema2),
                ],
            ),
            new RelationRegistry([
                new Relation(
                    'Dummy',
                    'recursive',
                    RelationOperation::QUERY,
                    'dummy1',
                    $resolver,
                ),
            ]),
        );

        $sdlExpecting = <<<'SDL'
type Dummy {
  id: ID!
  recursive(id: ID): Dummy! @delegate(subSchema: "a", operation: "query", operationField: "dummy1")
}
SDL;

        $this->assertStringContainsString($sdlExpecting, Printer::doPrint($builder->build()));

        $sdlExpecting = <<<'SDL'
type Dummy {
  id: ID!
  recursive: Dummy! @delegate(subSchema: "a", operation: "query", operationField: "dummy1")
}
SDL;

        $this->assertStringContainsString($sdlExpecting, Printer::doPrint($builder->build()));
    }
}
