<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Exception;

use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\Type;

final class ConflictException extends \RuntimeException implements ExceptionInterface
{
    /** @var string[] */
    public readonly array $schemas;

    /** @var Type[] */
    public readonly array $types;

    /** @var Directive[] */
    public readonly array $directives;

    public function setSchemas(array $schemas): void
    {
        $this->schemas = $schemas;
    }

    public function setConflictTypes(array $types): void
    {
        $this->types = $types;
    }

    public function setConflictDirectives(array $directives): void
    {
        $this->directives = $directives;
    }
}
