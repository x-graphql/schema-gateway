<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Execution;

use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;

final readonly class ExecutionResultMerger
{
    /**
     * @param ExecutionResult[] $results
     * @return ExecutionResult
     */
    public static function merge(array $results): ExecutionResult
    {
        $data = [];
        $errors = [];
        $extensions = [];

        foreach ($results as $result) {
            $data += $result->data ?? [];
            $extensions = array_merge($extensions, $result->extensions ?? []);
            $errors = array_merge($errors, $result->errors);
        }

        return new ExecutionResult(
            [] !== $data ? $data : null,
            $errors,
            $extensions,
        );
    }

    /**
     * @param ExecutionResult $result
     * @param ExecutionResult[][]|ExecutionResult[] $relationResults
     * @return ExecutionResult
     */
    public static function mergeWithRelationResults(ExecutionResult $result, array $relationResults): ExecutionResult
    {
        foreach ($relationResults as $relationResult) {
            $result->extensions = array_merge(
                $result->extensions ?? [],
                $relationResult->extensions ?? []
            );

            foreach ($relationResult->errors as $error) {
                $result->errors[] = new Error(
                    $error->getMessage(),
                    previous: $error,
                    extensions: array_merge([
                        [
                            'x_graphql' => [
                                'code' => 'relation_error'
                            ]
                        ],
                        $error->getExtensions() ?? [],
                    ]),
                );
            }
        }

        return $result;
    }
}
