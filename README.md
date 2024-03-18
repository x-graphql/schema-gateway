Schema Gateway
==============

![unit tests](https://github.com/x-graphql/schema-gateway/actions/workflows/unit_tests.yml/badge.svg)
[![codecov](https://codecov.io/gh/x-graphql/schema-gateway/graph/badge.svg?token=ODMTWZfOrL)](https://codecov.io/gh/x-graphql/schema-gateway)


![Copyright GraphQL Stitching](https://the-guild.dev/graphql/stitching/_next/static/media/distributed-graph.237d74a1.png)

**Image source: [GraphQL Stitching](https://the-guild.dev/graphql/stitching)**


Getting started
---------------

Install this package via [Composer](https://getcomposer.org)

```shell
composer require x-graphql/schema-gateway
```

Add `http-schema` package for creating and executing GraphQL schema over HTTP:

```shell
composer require x-graphql/http-schema
```

Usages
------

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use GraphQL\GraphQL;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Utils\SchemaPrinter;
use GraphQL\Type\Schema;
use XGraphQL\HttpSchema\HttpDelegator;
use XGraphQL\HttpSchema\HttpSchemaFactory;
use XGraphQL\SchemaGateway\MandatorySelectionSetProviderInterface;
use XGraphQL\SchemaGateway\Relation;
use XGraphQL\SchemaGateway\RelationArgumentResolverInterface;
use XGraphQL\SchemaGateway\RelationOperation;
use XGraphQL\SchemaGateway\SchemaGatewayFactory;
use XGraphQL\SchemaGateway\SubSchema;

$localSchema = new Schema([
    'query' => new ObjectType([
        'name' => 'Query',
        'fields' => [
            'person' => [
                'type' => new ObjectType([
                    'name' => 'Person',
                    'fields' => [
                        'name' => Type::nonNull(Type::string()),
                        'fromCountry' => Type::nonNull(Type::string()),
                    ],

                ]),
                'resolve' => fn() => ['name' => 'John Doe', 'fromCountry' => 'VN']
            ],
        ],
    ]),
]);
$localSubSchema = new SubSchema('local', $localSchema);

$remoteSchema = HttpSchemaFactory::createFromIntrospectionQuery(
    new HttpDelegator('https://countries.trevorblades.com/'),
);
$remoteSubSchema = new SubSchema('remote', $remoteSchema);

$countryRelation = new Relation(
    'Person',
    'remoteCountry',
    RelationOperation::QUERY,
    'country',
    new class implements RelationArgumentResolverInterface, MandatorySelectionSetProviderInterface {
        public function shouldKeep(string $argumentName, Relation $relation): bool
        {
            return false;
        }

        public function resolve(array $objectValue, array $currentArgs, Relation $relation): array
        {
            return ['code' => $objectValue['fromCountry']];
        }

        public function getMandatorySelectionSet(Relation $relation): string
        {
            return '{ fromCountry }';
        }
    }
);

$schemaGateway = SchemaGatewayFactory::create([$localSubSchema, $remoteSubSchema], [$countryRelation]);

$query = <<<'GQL'
query {
  continents {
    name
  }

  person {
    name
    remoteCountry {
      name
      code
    }
  }
}
GQL;

var_dump(SchemaPrinter::doPrint($schemaGateway));
var_dump(GraphQL::executeQuery($schemaGateway, $query)->toArray());
``` 

Inspiration
-----------

This library has been inspired by many others related work including:

+ [Hasura](https://hasura.io)
+ [GraphQL Stitching](https://the-guild.dev/graphql/stitching/)

Thanks to all the great people who created these projects!

Credits
-------

Created by [Minh Vuong](https://github.com/vuongxuongminh)
