<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\AST;

use GraphQL\Error\Error;
use GraphQL\Error\SerializationError;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputValueDefinitionNode;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\Type;
use GraphQL\Utils\SchemaPrinter;
use GraphQL\Validator\DocumentValidator;
use XGraphQL\SchemaGateway\Exception\ConflictException;
use XGraphQL\SchemaGateway\Exception\LogicException;
use XGraphQL\SchemaGateway\Exception\RuntimeException;
use XGraphQL\SchemaGateway\Relation;
use XGraphQL\SchemaGateway\RelationRegistry;
use XGraphQL\SchemaGateway\SubSchemaRegistry;

final readonly class ASTBuilder
{
    private const PRINT_OPTIONS = [
        'sortArguments' => true,
        'sortEnumValues' => true,
        'sortFields' => true,
        'sortInputFields' => true,
        'sortTypes' => true,
    ];

    public function __construct(
        private SubSchemaRegistry $subSchemaRegistry,
        private RelationRegistry $relationRegistry
    ) {
    }

    public function build(): DocumentNode
    {
        $sdl = implode(
            PHP_EOL,
            [
                $this->defineDirectives(),
                $this->defineOperationTypes(),
                $this->defineTypes(),
                DelegateDirective::definition(),
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
        $printer = (new \ReflectionMethod(SchemaPrinter::class, 'printDirective'))->getClosure();
        $definitions = $schemaDirectives = [];

        foreach ($this->subSchemaRegistry->subSchemas as $subSchema) {
            foreach ($subSchema->delegator->getSchema()->getDirectives() as $directive) {
                $name = $directive->name;
                $definition = $printer($directive, self::PRINT_OPTIONS);
                $compareWith = $definitions[$name] ?? null;
                $schemaDirectives[$name][] = $subSchema->name;

                if (null !== $compareWith && $definition !== $compareWith) {
                    throw new ConflictException(
                        sprintf(
                            'Directive conflict: `%s` with `%s`',
                            $definition,
                            $compareWith,
                        ),
                        $schemaDirectives[$name],
                    );
                }

                $definitions[$name] = $definition;
            }
        }

        return implode(PHP_EOL, $definitions);
    }

    /**
     * @throws SerializationError
     * @throws \JsonException
     * @throws Error
     */
    private function defineOperationTypes(): string
    {
        $definitions = [];

        foreach (['query', 'mutation', 'subscription'] as $operation) {
            $definitions[] = $this->defineOperationType($operation);
        }

        return implode(PHP_EOL, $definitions);
    }

    /**
     * @throws SerializationError
     * @throws \JsonException
     * @throws Error
     */
    private function defineOperationType(string $operation): string
    {
        $schemaFields = [];
        $fields = new NodeList([]);

        foreach ($this->subSchemaRegistry->subSchemas as $subSchema) {
            $type = $subSchema->delegator->getSchema()->getOperationType($operation);

            if (null === $type) {
                continue;
            }

            $sdl = SchemaPrinter::printType($type);
            $typeDefinition = Parser::objectTypeDefinition($sdl, ['noLocation' => true]);

            foreach ($typeDefinition->fields as $field) {
                /** @var FieldDefinitionNode $field */

                $fieldName = $field->name->value;
                $schemaFields[$fieldName][] = $subSchema->name;

                if (isset($fields[$fieldName])) {
                    throw new ConflictException(
                        sprintf(
                            'Duplicated field `%s` on %s root type',
                            $fieldName,
                            $operation,
                        ),
                        $schemaFields[$fieldName]
                    );
                }

                $fields[$fieldName] = $field;
                $field->directives = Parser::directives(
                    sprintf(
                        '@%s(subSchema: "%s", operation: "%s", operationField: "%s")',
                        DelegateDirective::NAME,
                        $subSchema->name,
                        $operation,
                        $fieldName,
                    ),
                    ['noLocation' => true]
                );
            }
        }

        if (0 === $fields->count()) {
            return '';
        }

        $definition = Parser::objectTypeDefinition(sprintf('type %s', ucfirst($operation)), ['noLocation' => true]);
        $definition->fields = $fields;

        return Printer::doPrint($definition);
    }

    /**
     * @throws SerializationError
     * @throws \JsonException
     * @throws Error
     */
    private function defineTypes(): string
    {
        $definitions = $schemaTypes = [];

        foreach ($this->subSchemaRegistry->subSchemas as $subSchema) {
            $schema = $subSchema->delegator->getSchema();
            $queryType = $schema->getQueryType();
            $mutationType = $schema->getMutationType();
            $subscriptionType = $schema->getSubscriptionType();

            foreach ($schema->getTypeMap() as $name => $type) {
                /** @var Type&NamedType $type */

                if ($type === $queryType || $type === $mutationType || $type === $subscriptionType) {
                    continue;
                }

                $definition = SchemaPrinter::printType($type, self::PRINT_OPTIONS);
                $compareWith = $definitions[$name] ?? null;
                $schemaTypes[$name][] = $subSchema->name;

                if (null !== $compareWith && $definition !== $compareWith) {
                    throw new ConflictException(
                        sprintf('Type conflict: `%s` with `%s`', $definition, $compareWith),
                        $schemaTypes[$name],
                    );
                }

                $definitions[$name] = $definition;
            }
        }

        return implode(PHP_EOL, $definitions);
    }

    /**
     * @param array<string, TypeDefinitionNode> $types
     */
    private function addTypesRelations(array $types): void
    {
        foreach ($this->relationRegistry->relations as $relation) {
            if (in_array($relation->onType, ['Query', 'Mutation', 'Subscription'], true)) {
                throw new RuntimeException(
                    sprintf('Add relations on `%s` operation type are not supported!', $relation->onType)
                );
            }

            $onType = $types[$relation->onType] ?? null;
            $operationType = $types[ucfirst($relation->operation->value)] ?? null;

            if (null === $onType || null === $operationType) {
                throw new LogicException(
                    sprintf(
                        'Type `%s` and operation `%s` should be exists on schema',
                        $relation->onType,
                        $relation->operation->value,
                    )
                );
            }

            if (!$onType instanceof ObjectTypeDefinitionNode || !$operationType instanceof ObjectTypeDefinitionNode) {
                throw new RuntimeException('Only support to add relation between object types');
            }

            $this->duplicateRelationFieldGuard($onType, $relation->field);

            $onType->fields[] = $this->makeRelationField($operationType, $relation);
        }
    }

    private function duplicateRelationFieldGuard(ObjectTypeDefinitionNode $type, string $fieldName): void
    {
        foreach ($type->fields as $existField) {
            /** @var FieldDefinitionNode $existField */
            if ($existField->name->value === $fieldName) {
                throw new LogicException(
                    sprintf(
                        'Duplicated relation field `%s` on type `%s`',
                        $existField->name->value,
                        $type->name->value
                    )
                );
            }
        }
    }

    private function makeRelationField(ObjectTypeDefinitionNode $operationType, Relation $relation): FieldDefinitionNode
    {
        $relationDef = null;

        foreach ($operationType->fields as $fieldDef) {
            /** @var FieldDefinitionNode $fieldDef */

            if ($fieldDef->name->value !== $relation->operationField) {
                continue;
            }

            $relationDef = $fieldDef->cloneDeep();

            break;
        }

        if (null === $relationDef) {
            throw new LogicException(
                sprintf(
                    'Not found field name `%s` on operation type `%s`',
                    $relation->operationField,
                    $operationType->name->value,
                )
            );
        }

        $relationDef->name->value = $relation->field;

        foreach ($relationDef->arguments as $pos => $argument) {
            /** @var InputValueDefinitionNode $argument */

            if (!$relation->argResolver->shouldKeep($argument->name->value, $relation)) {
                unset($relationDef->arguments[$pos]);

                continue;
            }

            if ($argument->type instanceof NonNullTypeNode) {
                //// All argument of relation field can be null,
                ///  arg resolver will have responsible to resolve mandatory arg on runtime.
                $argument->type = $argument->type->type;
            }
        }

        $relationDef->arguments->reindex();

        return $relationDef;
    }
}
