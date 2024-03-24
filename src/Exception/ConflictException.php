<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Exception;

final class ConflictException extends RuntimeException implements ExceptionInterface
{
    public function __construct(string $message, public readonly array $schemas)
    {
        parent::__construct($message);
    }
}
