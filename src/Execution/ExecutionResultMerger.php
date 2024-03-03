<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Execution;

use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;

final readonly class ExecutionResultMerger
{
    public static function mergeSubResults(array $results): ExecutionResult
    {
        $data = [];
        $errors = [];
        $extensions = [];

        foreach ($results as $result) {
            /** @var ExecutionResult $result */

            $data = array_merge($data, $result->data ?? []);
            $extensions = array_merge($extensions, $result->extensions ?? []);
            $errors = array_merge($errors, $result->errors);
        }

        return new ExecutionResult(
            [] !== $data ? $data : null,
            $errors,
            $extensions,
        );
    }

    public static function mergeRelationResults(ExecutionResult $result, array $relationResults): ExecutionResult
    {
        foreach ($relationResults as $relationResult) {
            /** @var ExecutionResult $relationResult */

            $result->extensions = array_merge(
                $result->extensions ?? [],
                $relationResult->extensions ?? []
            );

            foreach ($relationResult->errors as $error) {
                $result->errors[] = new Error(
                    $error->getMessage(),
                    previous: $error,
                    extensions: [
                        ...($error->getExtensions() ?? []),
                        [
                            'x_graphql' => 'relation_error'
                        ]
                    ]
                );
            }
        }

        return $result;
    }
}
