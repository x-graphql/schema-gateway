<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Test;

use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XGraphQL\DelegateExecution\ErrorsReporterInterface;
use XGraphQL\HttpSchema\HttpDelegator;
use XGraphQL\HttpSchema\HttpSchemaFactory;
use XGraphQL\SchemaGateway\MandatorySelectionSetProviderInterface;
use XGraphQL\SchemaGateway\Relation;
use XGraphQL\SchemaGateway\RelationArgumentResolverInterface;
use XGraphQL\SchemaGateway\RelationOperation;
use XGraphQL\SchemaGateway\SchemaGatewayFactory;
use XGraphQL\SchemaGateway\SubSchema;

class IntegrationTest extends TestCase
{
    #[DataProvider(methodName: 'executionProvider')]
    public function testExecuteQueryHaveOperationFragments(
        string $query,
        array $variables,
        array $expectingResult
    ): void {
        $schema = $this->createSchemaGateway(
            //            new class implements ErrorsReporterInterface
            //            {
            //
            //                public function reportErrors(array $errors): void
            //                {
            //                    var_dump($errors); die;
            //                }
            //            }
        );
        $result = GraphQL::executeQuery($schema, $query, variableValues: $variables)->toArray(
            DebugFlag::INCLUDE_DEBUG_MESSAGE
        );

        $this->assertSame($expectingResult, $result);
    }

    public static function executionProvider(): array
    {
        return [
            'have fragments on operation type' => [
                <<<'GQL'
fragment QueryFragment on Query {
  person {
    name
  }

  language(code: $lang) {
    name
  }

  ...AnotherQueryFragment
}

fragment AnotherQueryFragment on Query {
  person {
    languages
  }
}

query($lang: ID!) {
  person {
    fromCountry
  }

  language(code: $lang) {
    code
  }

  ...QueryFragment

  ... on Query {
   language(code: $lang) {
     person {
       name
     }
   }
  }
}
GQL,
                ['lang' => 'vi'],
                [
                    'data' => [
                        'person' => [
                            'fromCountry' => 'VN',
                            'name' => 'John Doe',
                            'languages' => ['vi', 'en']
                        ],
                        'language' => [
                            'code' => 'vi',
                            'name' => 'Vietnamese',
                            'person' => [
                                'name' => 'John Doe vi'
                            ]
                        ]
                    ]
                ]
            ],
            'select field relations' => [
                <<<'GQL'
query {
  person {
    remoteCountry {
      code
      name
    }

    remoteLanguages {
      code
      name
    }
  }
}
GQL,
                [],
                [
                    'data' => [
                        'person' => [
                            'remoteCountry' => [
                                'code' => 'VN',
                                'name' => 'Vietnam'
                            ],
                            'remoteLanguages' => [
                                [
                                    'code' => 'en',
                                    'name' => 'English'
                                ],
                                [
                                    'code' => 'vi',
                                    'name' => 'Vietnamese'
                                ],
                            ]
                        ],
                    ]
                ]
            ],
            'select field relation conflict sdl with upstream' => [
                <<<'GQL'
query($lang: ID!) {
  person {
    name
    remoteCountry {
      notExist
    }
  }

  language(code: $lang) {
    name
  }
}
GQL,
                ['lang' => 'vi'],
                [
                    'errors' => [
                        [
                            'message' => 'Internal server error',
                            'locations' => [['line' => 4, 'column' => 5]],
                            'path' => ['person', 'remoteCountry'],
                            'extensions' => [
                                'debugMessage' => 'Delegated execution result is missing field value at path: `person.remoteCountry`'
                            ]
                        ]
                    ],
                    'data' => [
                        'person' => [
                            'name' => 'John Doe',
                            'remoteCountry' => null,
                        ],
                        'language' => [
                            'name' => 'Vietnamese'
                        ]
                    ]
                ],
            ],
            'use fragments on relation fields' => [
                <<<'GQL'
fragment QueryFragment on Query {
  person {
    name
    remoteCountry {
      code
    }
    remoteLanguages {
      code
    }
  }
}

fragment CountryFragment on Country {
  name
}

fragment LanguageFragment on Language {
  name
}

query($lang: ID!) {
  ...QueryFragment

  person {
    name
    fromCountry
    remoteCountry {
      ...CountryFragment
    }
    remoteLanguages {
      ...LanguageFragment
    }
  }

  language(code: $lang) {
    ...LanguageFragment
  }
}
GQL,
                ['lang' => 'en'],
                [
                    'data' => [
                        'person' => [
                            'name' => 'John Doe',
                            'remoteCountry' => [
                                'code' => 'VN',
                                'name' => 'Vietnam',
                            ],
                            'remoteLanguages' => [
                                [
                                    'code' => 'en',
                                    'name' => 'English',
                                ],
                                [
                                    'code' => 'vi',
                                    'name' => 'Vietnamese',
                                ],
                            ],
                            'fromCountry' => 'VN'
                        ],
                        'language' => [
                            'name' => 'English',
                        ]
                    ]
                ],
            ],
            'select relation on object' => [
                <<<'GQL'
query($willBeOverwriteToUS: ID!) {
  person {
    name
    device {
      countryCode
      remoteCountry(code: $willBeOverwriteToUS) {
        name
      }
    }
  }
}
GQL,
                ['willBeOverwriteToUS' => 'VN'],
                [
                    'data' => [
                        'person' => [
                            'name' => 'John Doe',
                            'device' => [
                                'countryCode' => 'US',
                                'remoteCountry' => [
                                    'name' => 'United States',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'select relations on list' => [
                <<<'GQL'
query($filter: LanguageFilterInput) {
  languages(filter: $filter) {
    name
    code
    person {
      name
    }
  }
}
GQL,
                [
                    'filter' => [
                        'code' => [
                            'in' => ['en', 'fr']
                        ]
                    ]
                ],
                [
                    'data' => [
                        'languages' => [
                            [
                                'name' => 'English',
                                'code' => 'en',
                                'person' => [
                                    'name' => 'John Doe en'
                                ]
                            ],
                            [
                                'name' => 'French',
                                'code' => 'fr',
                                'person' => [
                                    'name' => 'John Doe fr'
                                ]
                            ],
                        ]
                    ]
                ]
            ],
            'select relations on list of list fields' => [
                <<<'GQL'
query {
  personPerson {
    name
    remoteCountry {
      name
    }
  }
}
GQL,
                [],
                [
                    'data' => [
                        'personPerson' => [
                            [
                                [
                                    'name' => 'John Doe 1',
                                    'remoteCountry' => [
                                        'name' => 'United States'
                                    ]
                                ],
                                [
                                    'name' => 'John Doe 2',
                                    'remoteCountry' => [
                                        'name' => 'Canada'
                                    ]
                                ],
                            ],
                            [
                                [
                                    'name' => 'John Doe 3',
                                    'remoteCountry' => [
                                        'name' => 'France'
                                    ]
                                ]
                            ],
                        ]
                    ]
                ]
            ],
            'select relations on deep list' => [
                <<<'GQL'
query($filter: CountryFilterInput!) {
  countries(filter: $filter) {
    name
    languages {
      name
      code
      person {
        name
        remoteLanguages {
          name
        }
      }
    }
  }
}
GQL,
                [
                    'filter' => [
                        'code' => [
                            'in' => ['US', 'VN']
                        ]
                    ]
                ],
                [
                    'data' => [
                        'countries' => [
                            [
                                'name' => 'United States',
                                'languages' => [
                                    [
                                        'name' => 'English',
                                        'code' => 'en',
                                        'person' => [
                                            'name' => 'John Doe en',
                                            'remoteLanguages' => [
                                                [
                                                    'name' => 'English',
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ],
                            [
                                'name' => 'Vietnam',
                                'languages' => [
                                    [
                                        'name' => 'Vietnamese',
                                        'code' => 'vi',
                                        'person' => [
                                            'name' => 'John Doe vi',
                                            'remoteLanguages' => [
                                                [
                                                    'name' => 'Vietnamese',
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'use null variable' => [
                <<<'GQL'
query($code: ID) {
  findPersonByLanguageCode(code: $code) {
    name
  }
}
GQL,
                [
                    'code' => null
                ],
                [
                    'data' => [
                        'findPersonByLanguageCode' => [
                            'name' => 'John Doe unknown',
                        ]
                    ]
                ],
            ]
        ];
    }

    private function createSchemaGateway(ErrorsReporterInterface $errorsReporter = null): Schema
    {
        $localSchema = new SubSchema('local', $this->createLocalSchema());
        $remoteSchema = new SubSchema('remote', $this->createRemoteSchema($errorsReporter));
        $personCountryRelation = new Relation(
            'Person',
            'remoteCountry',
            RelationOperation::QUERY,
            'country',
            new class() implements
                RelationArgumentResolverInterface,
                MandatorySelectionSetProviderInterface {
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
        $personLanguagesRelation = new Relation(
            'Person',
            'remoteLanguages',
            RelationOperation::QUERY,
            'languages',
            new class() implements
                RelationArgumentResolverInterface,
                MandatorySelectionSetProviderInterface {
                public function shouldKeep(string $argumentName, Relation $relation): bool
                {
                    return false;
                }

                public function resolve(array $objectValue, array $currentArgs, Relation $relation): array
                {
                    return [
                        'filter' => [
                            'code' => [
                                'in' => $objectValue['languages']
                            ]
                        ]
                    ];
                }

                public function getMandatorySelectionSet(Relation $relation): string
                {
                    return '{ languages }';
                }
            }
        );
        $languagePersonRelation = new Relation(
            'Language',
            'person',
            RelationOperation::QUERY,
            'findPersonByLanguageCode',
            new class() implements RelationArgumentResolverInterface {
                public function shouldKeep(string $argumentName, Relation $relation): bool
                {
                    return false;
                }

                public function resolve(array $objectValue, array $currentArgs, Relation $relation): array
                {
                    return ['code' => $objectValue['code']];
                }
            }
        );
        $deviceCountryRelation = new Relation(
            'Device',
            'remoteCountry',
            RelationOperation::QUERY,
            'country',
            new class() implements
                RelationArgumentResolverInterface,
                MandatorySelectionSetProviderInterface {
                public function getMandatorySelectionSet(Relation $relation): string
                {
                    return '{ countryCode }';
                }

                public function shouldKeep(string $argumentName, Relation $relation): bool
                {
                    return true;
                }

                public function resolve(array $objectValue, array $currentArgs, Relation $relation): array
                {
                    return ['code' => $objectValue['countryCode']];
                }
            }
        );
        return SchemaGatewayFactory::create(
            [$localSchema, $remoteSchema],
            [$personCountryRelation, $personLanguagesRelation, $languagePersonRelation, $deviceCountryRelation],
            errorsReporter: $errorsReporter
        );
    }

    private function createLocalSchema(): Schema
    {
        $personType = new ObjectType([
            'name' => 'Person',
            'fields' => [
                'name' => Type::nonNull(Type::string()),
                'fromCountry' => Type::string(),
                'languages' => Type::listOf(Type::string()),
                'device' => [
                    'type' => new ObjectType([
                        'name' => 'Device',
                        'fields' => [
                            'countryCode' => Type::nonNull(Type::id()),
                        ]
                    ]),
                    'resolve' => fn () => ['countryCode' => 'US']
                ]
            ]
        ]);

        return new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => [
                    'person' => [
                        'type' => $personType,
                        'resolve' => fn () => [
                            'name' => 'John Doe',
                            'fromCountry' => 'VN',
                            'languages' => ['vi', 'en'],
                        ]
                    ],
                    'personPerson' => [
                        'type' => Type::listOf(Type::listOf($personType)),
                        'resolve' => fn () => [
                            [
                                [
                                    'name' => 'John Doe 1',
                                    'fromCountry' => 'US',
                                ],
                                [
                                    'name' => 'John Doe 2',
                                    'fromCountry' => 'CA',
                                ]
                            ],
                            [
                                [
                                    'name' => 'John Doe 3',
                                    'fromCountry' => 'FR',
                                ],
                            ]

                        ],
                    ],
                    'findPersonByLanguageCode' => [
                        'type' => $personType,
                        'args' => [
                            'code' => Type::id(),
                        ],
                        'resolve' => fn (mixed $rootValue, array $args) => [
                            'name' => 'John Doe ' . ($args['code'] ?? 'unknown'),
                            'languages' => (array)$args['code'],
                        ],
                    ]
                ],
            ])
        ]);
    }

    private function createRemoteSchema(ErrorsReporterInterface $errorsReporter = null): Schema
    {
        $delegator = new HttpDelegator('https://countries.trevorblades.com/');
        $sdl = <<<'SDL'
type Query {
  countries(filter: CountryFilterInput): [Country!]!
  languages(filter: LanguageFilterInput): [Language!]!
  country(code: ID!): Country
  language(code: ID!): Language
}

type Country {
  name: String!
  code: ID!
  languages: [Language!]!
  notExist: ID
}

type Language {
  name: String!
  code: ID!
}

input CountryFilterInput {
  code: StringQueryOperatorInput
}

input LanguageFilterInput {
  code: StringQueryOperatorInput
}

input StringQueryOperatorInput {
  in: [String!]
}
SDL;

        return HttpSchemaFactory::createFromSDL($delegator, $sdl, errorsReporter: $errorsReporter);
    }
}
