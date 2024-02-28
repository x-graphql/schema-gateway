<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway;

use XGraphQL\SchemaGateway\Exception\InvalidArgumentException;

final class SubSchemaRegistry
{
    private ?array $mapping = null;

    /**
     * @param iterable<SubSchema> $subSchemas
     */
    public function __construct(public readonly iterable $subSchemas)
    {
    }

    private function prepareMapping(): void
    {
        if (null !== $this->mapping) {
            return;
        }

        foreach ($this->subSchemas as $subSchema) {
            $this->mapping[$subSchema->name] = $subSchema;
        }
    }

    public function getSubSchema(string $name): SubSchema
    {
        $this->prepareMapping();

        if (!isset($this->mapping[$name])) {
            throw new InvalidArgumentException(sprintf('Sub schema: `%s` not found', $name));
        }

        return $this->mapping[$name];
    }

    public function hasSubSchema(string $name): bool
    {
        return isset($this->mapping[$name]);
    }
}
