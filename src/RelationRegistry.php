<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway;

use XGraphQL\SchemaGateway\Exception\InvalidArgumentException;

final class RelationRegistry
{
    private ?array $mapping = null;

    /**
     * @param iterable<Relation> $relations
     */
    public function __construct(public readonly iterable $relations)
    {
    }

    private function prepareMapping(): void
    {
        if (null !== $this->mapping) {
            return;
        }

        $mapping = [];

        foreach ($this->relations as $relation) {
            $mapping[$relation->onType][$relation->field] = $relation;
        }

        $this->mapping = $mapping;
    }

    public function getRelationByTypeField(string $type, string $field): Relation
    {
        $this->prepareMapping();

        if (!isset($this->mapping[$type][$field])) {
            throw new InvalidArgumentException(
                sprintf('Not found relation field: `%s` on type: `%s`', $field, $type)
            );
        }

        return $this->mapping[$type][$field];
    }

}
