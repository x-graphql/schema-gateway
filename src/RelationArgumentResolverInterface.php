<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway;

use GraphQL\Language\AST\SelectionSetNode;

interface RelationArgumentResolverInterface
{
    /// Whether to keep arg on relation field or not.
    public function shouldKeep(string $argumentName, Relation $relation): bool;

    /// Provides mandatory selection set on relation type help to resolve arguments.
    public function getMandatorySelectionSet(Relation $relation): string;

    /// Return arguments values of operation field use for selecting data of relation field
    public function resolve(array $objectValue, array $currentArgs, Relation $relation): array;
}
