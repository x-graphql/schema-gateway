<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway;

enum RelationOperation: string
{
    case QUERY = 'Query';

    case MUTATION = 'Mutation';

    case SUBSCRIPTION = 'Subscription';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(fn(self $type) => $type->value, self::cases());
    }
}
