<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Execution;

use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\OperationDefinitionNode;

final class SubQuery
{
    /** @var \SplObjectStorage<SubQuery, string> */
    private \SplObjectStorage $subQueries;

    /**
     * @param string $subSchemaName
     * @param OperationDefinitionNode $operation
     * @param FragmentDefinitionNode[] $fragments
     * @param array $variables
     */
    public function __construct(
        public readonly string $subSchemaName,
        public readonly OperationDefinitionNode $operation,
        public readonly array $fragments,
        public readonly array $variables,
    ) {
        $this->subQueries = new \SplObjectStorage();
    }

    /// Add sub query for path of this query result need to resolve
    public function addSubQueryForPath(string $path, SubQuery $subQuery): void
    {
        $this->subQueries[$subQuery] = $path;
    }

    public function getSubQueries(): iterable
    {
        return new \IteratorIterator($this->subQueries);
    }
}
