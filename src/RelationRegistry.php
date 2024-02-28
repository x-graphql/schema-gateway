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

    public function getRelation(string $onType, string $field): Relation
    {
        $this->prepareMapping();

        if (!isset($this->mapping[$onType][$field])) {
            throw new InvalidArgumentException(
                sprintf('Not found relation field: `%s` on type: `%s`', $field, $onType)
            );
        }

        return $this->mapping[$onType][$field];
    }

    public function hasRelation(string $onType, string $field): bool
    {
        return isset($this->mapping[$onType][$field]);
    }
}
