<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway;

interface RelationMandatorySelectionSetProviderInterface
{
    /// Provides mandatory selection set on relation type help to resolve arguments.
    public function getMandatorySelectionSet(Relation $relation): string;
}
