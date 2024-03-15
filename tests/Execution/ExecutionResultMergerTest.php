<?php

declare(strict_types=1);

namespace XGraphQL\SchemaGateway\Test\Execution;

use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;
use PHPUnit\Framework\TestCase;
use XGraphQL\SchemaGateway\Execution\ExecutionResultMerger;

class ExecutionResultMergerTest extends TestCase
{
    public function testMerge(): void
    {
        $errorA = new Error();
        $errorB = new Error();
        $errorC = new Error();

        $resultA = new ExecutionResult(['a' => null], [$errorA], ['a']);
        $resultB = new ExecutionResult(['b' => null], [$errorB], ['b']);
        $resultC = new ExecutionResult(['c' => null], [$errorC], ['c']);

        $result = ExecutionResultMerger::merge([$resultA, $resultB, $resultC]);


        $this->assertSame(array_fill_keys(['a', 'b', 'c'], null), $result->data);
        $this->assertSame([$errorA, $errorB, $errorC], $result->errors);
        $this->assertSame(['a', 'b', 'c'], $result->extensions);
    }

    public function testMergeRelationResults(): void
    {
        $errorA = new Error();
        $errorB = new Error();
        $errorC = new Error();

        $resultA = new ExecutionResult(['a' => null], [$errorA], ['a']);
        $resultB = new ExecutionResult(['b' => null], [$errorB], ['b']);
        $resultC = new ExecutionResult(['c' => null], [$errorC], ['c']);

        $result = new ExecutionResult();

        ExecutionResultMerger::mergeWithRelationResults($result, [$resultA, $resultB, $resultC]);

        $this->assertNull($result->data);
        $this->assertSame(
            [$errorA, $errorB, $errorC],
            array_map(fn(Error $error) => $error->getPrevious(), $result->errors)
        );
        $this->assertSame(['a', 'b', 'c'], $result->extensions);
    }
}
