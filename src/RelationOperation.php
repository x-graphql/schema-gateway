<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway;

enum RelationOperation: string
{
    case QUERY = 'query';

    case MUTATION = 'mutation';

    case SUBSCRIPTION = 'subscription';
}
