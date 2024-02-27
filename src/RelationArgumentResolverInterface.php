<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway;

use GraphQL\Language\AST\SelectionSetNode;

interface RelationArgumentResolverInterface
{
    /// Whether to keep arg on relation field or not.
    public function shouldKeep(string $argumentName, Relation $relation): bool;

    /// Get mandatory selections on relation type help to resolve arguments.
    public function getAdditionalSelections(Relation $relation): array;

    /// Return arguments values of operation field use for selecting data of relation field
    public function resolve(array $objectValue, array $currentArgs, Relation $relation): array;
}
