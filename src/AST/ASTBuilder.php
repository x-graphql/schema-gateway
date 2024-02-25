<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\AST;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputValueDefinitionNode;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\Type;
use GraphQL\Utils\SchemaPrinter;
use GraphQL\Validator\DocumentValidator;
use XGraphQL\SchemaGateway\Exception\ConflictException;
use XGraphQL\SchemaGateway\Exception\LogicException;
use XGraphQL\SchemaGateway\Exception\RuntimeException;
use XGraphQL\SchemaGateway\Relation\OperationType;
use XGraphQL\SchemaGateway\Relation\Relation;
use XGraphQL\SchemaGateway\SubSchema;

final readonly class ASTBuilder
{
    /**
     * @param SubSchema[] $subSchemas
     * @param Relation[] $relations
     */
    public function __construct(private iterable $subSchemas, private iterable $relations)
    {
    }

    public function build(): DocumentNode
    {
        $sdl = implode(
            PHP_EOL,
            [
                $this->defineDirectives(),
                $this->defineOperationTypes(),
                $this->defineTypes()
            ]
        );

        $ast = Parser::parse($sdl, ['noLocation' => true]);
        $types = [];

        foreach ($ast->definitions as $definition) {
            if ($definition instanceof TypeDefinitionNode) {
                $types[$definition->getName()->value] = $definition;
            }
        }

        $this->addTypesRelations($types);

        DocumentValidator::assertValidSDL($ast);

        return $ast;
    }

    private function defineDirectives(): string
    {
        $directives = $schemaDirectives = [];

        foreach ($this->subSchemas as $subSchema) {
            foreach ($subSchema->delegator->getSchema()->getDirectives() as $directive) {
                $name = $directive->name;
                $compareWith = $directives[$name] ?? null;
                $schemaDirectives[$name][] = $subSchema->name;

                if (null !== $compareWith) {
                    try {
                        ConflictGuard::directiveGuard($directive, $compareWith);
                    } catch (ConflictException $exception) {
                        $exception->setSchemas($schemaDirectives[$name]);
                        $exception->setConflictDirectives([$directive, $compareWith]);

                        throw $exception;
                    }
                }

                $directives[$name] = clone $directive;
            }
        }

        $printer = (new \ReflectionMethod(SchemaPrinter::class, 'printDirective'))->getClosure();
        $definitions = array_map(fn(Directive $directive) => $printer($directive, []), $directives);

        return implode(PHP_EOL, $definitions);
    }

    private function defineOperationTypes(): string
    {
        $definitions = [];

        foreach (['query', 'mutation', 'subscription'] as $operation) {
            $definitions[] = $this->defineOperationType($operation);
        }

        return implode(PHP_EOL, $definitions);
    }

    private function defineOperationType(string $operation): string
    {
        $schemaFields = [];
        $fields = new NodeList([]);

        foreach ($this->subSchemas as $subSchema) {
            $type = $subSchema->delegator->getSchema()->getOperationType($operation);

            if (null === $type) {
                continue;
            }

            $sdl = SchemaPrinter::printType($type);
            $typeDefinition = Parser::objectTypeDefinition($sdl);

            foreach ($typeDefinition->fields as $field) {
                /** @var FieldDefinitionNode $field */

                $fieldName = $field->name->value;
                $schemaFields[$fieldName][] = $subSchema->name;

                if (isset($fields[$fieldName])) {
                    $exception = new ConflictException(
                        sprintf(
                            'Duplicated field `%s` on %s root type',
                            $fieldName,
                            $operation,
                        )
                    );
                    $exception->setSchemas($schemaFields[$fieldName]);

                    throw $exception;
                }

                $fields[$fieldName] = $field;
                $field->directives = Parser::directives(sprintf('@delegate(schema: "%s")', $subSchema->name));
            }
        }

        if (0 === $fields->count()) {
            return '';
        }

        $definition = Parser::objectTypeDefinition(sprintf('type %s', ucfirst($operation)));
        $definition->fields = $fields;

        return Printer::doPrint($definition);
    }

    private function defineTypes(): string
    {
        $types = $schemaTypes = [];

        foreach ($this->subSchemas as $subSchema) {
            $schema = $subSchema->delegator->getSchema();
            $queryType = $schema->getQueryType();
            $mutationType = $schema->getMutationType();
            $subscriptionType = $schema->getSubscriptionType();

            foreach ($schema->getTypeMap() as $name => $type) {
                /** @var Type&NamedType $type */

                if ($type === $queryType || $type === $mutationType || $type === $subscriptionType) {
                    continue;
                }

                $compareWith = $types[$name] ?? null;
                $schemaTypes[$name][] = $subSchema->name;

                if (null !== $compareWith) {
                    try {
                        ConflictGuard::typeGuard($type, $compareWith);
                    } catch (ConflictException $exception) {
                        $exception->setSchemas($schemaTypes[$name]);
                        $exception->setConflictTypes([$type, $compareWith]);

                        throw $exception;
                    }
                }

                $types[$name] = $type;
            }
        }

        $definitions = array_map([SchemaPrinter::class, 'printType'], $types);

        return implode(PHP_EOL, $definitions);
    }

    /**
     * @param array<string, TypeDefinitionNode> $types
     */
    private function addTypesRelations(array $types): void
    {
        foreach ($this->relations as $relation) {
            if (in_array($relation->onType, OperationType::values(), true)) {
                throw new RuntimeException(sprintf('Add relations on `%s` operation type are not supported!', $relation->onType));
            }

            $onType = $types[$relation->onType] ?? null;
            $operationType = $types[$relation->operationType->value] ?? null;

            if (null === $onType || null === $operationType) {
                throw new LogicException(
                    sprintf(
                        'Type `%s` and operation type `%s` should be exists on schema',
                        $relation->onType,
                        $relation->rootType
                    )
                );
            }

            assert($onType instanceof ObjectTypeDefinitionNode);

            assert($operationType instanceof ObjectTypeDefinitionNode);

            $this->duplicateRelationFieldGuard($onType, $relation->field);

            $onType->fields[] = $clonedField = $this->cloneOperationField($operationType, $relation->rootField);

            $clonedField->name->value = $relation->field;
        }
    }

    private function duplicateRelationFieldGuard(ObjectTypeDefinitionNode $type, string $fieldName): void
    {
        foreach ($type->fields as $existField) {
            /** @var FieldDefinitionNode $existField */
            if ($existField->name->value === $fieldName) {
                throw new LogicException(
                    sprintf(
                        'Duplicated field `%s` on type `%s`',
                        $existField->name->value,
                        $type->name->value
                    )
                );
            }
        }
    }

    private function cloneOperationField(ObjectTypeDefinitionNode $operationType, string $field): FieldDefinitionNode
    {
        $clonedFieldDefinition = null;

        foreach ($operationType->fields as $fieldDefinition) {
            /** @var FieldDefinitionNode $fieldDefinition */

            if ($fieldDefinition->name->value !== $field) {
                continue;
            }

            $clonedFieldDefinition = $fieldDefinition->cloneDeep();

            foreach ($clonedFieldDefinition->arguments as $argument) {
                /** @var InputValueDefinitionNode $argument */

                if ($argument->type instanceof NonNullTypeNode) {
                    //// All argument of relation field can be null,
                    ///  arg resolver will resolve mandatory arg on runtime.
                    $argument->type = $argument->type->type;
                }
            }

            $clonedFieldDefinition->directives = Parser::directives(
                sprintf(
                    '@%s(operation: "%s", field: "%s")',
                    RelationDirective::NAME,
                    $operationType->getName()->value,
                    $field,
                )
            );

            break;
        }

        if (null === $clonedFieldDefinition) {
            throw new LogicException(
                sprintf(
                    'Not found field name `%s` on operation type `%s`',
                    $field,
                    $operationType->name->value,
                )
            );
        }


        return $clonedFieldDefinition;
    }
}
