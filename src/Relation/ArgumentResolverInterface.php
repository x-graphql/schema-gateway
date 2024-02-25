<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Relation;

use GraphQL\Language\AST\SelectionSetNode;

interface ArgumentResolverInterface
{
    /// Enhance selection set when querying to add more selection field needed to resolve value
    public function enhanceSelectionSet(SelectionSetNode $selectionSet, Relation $relation): void;

    /// Return arguments values of operation field use for selecting data of relation field
    public function resolve(array $objectValue, Relation $relation): array;
}
