<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Execution;

use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;
use XGraphQL\SchemaGateway\Exception\InvalidArgumentException;

final readonly class ExecutionResultMerger
{
    /**
     * @param ExecutionResult[] $results
     * @return ExecutionResult
     */
    public static function mergeSubResults(array $results): ExecutionResult
    {
        $data = [];
        $errors = [];
        $extensions = [];

        foreach ($results as $result) {
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

    /**
     * @param ExecutionResult $result
     * @param ExecutionResult[][]|ExecutionResult[] $relationResults
     * @return ExecutionResult
     */
    public static function mergeRelationResults(ExecutionResult $result, array $relationResults): ExecutionResult
    {
        foreach ($relationResults as $relationResult) {
            /// list of relations
            if (is_array($relationResult)) {
                self::mergeRelationResults($result, $relationResult);

                continue;
            }

            if (!$relationResult instanceof ExecutionResult) {
                throw new InvalidArgumentException(
                    sprintf('Elements of `$relationResults` should be instance of: `%s`', ExecutionResult::class)
                );
            }

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
