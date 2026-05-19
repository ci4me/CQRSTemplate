<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base Test Case for Unit Tests.
 *
 * Unit tests should:
 * - Be fast (no DB, no external dependencies)
 * - Test one thing in isolation
 * - Use mocks for dependencies
 * - Follow AAA pattern (Arrange-Act-Assert)
 *
 * @package Tests\Support
 */
abstract class UnitTestCase extends TestCase
{
    /**
     * Assert that an exception is thrown with a specific message.
     *
     * @param string $expectedMessage Expected exception message
     * @param callable $callback Code that should throw
     */
    protected function assertExceptionMessage(string $expectedMessage, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertStringContainsString(
                needle: $expectedMessage,
                haystack: $e->getMessage(),
                message: sprintf(
                    'Exception message "%s" does not contain "%s"',
                    $e->getMessage(),
                    $expectedMessage
                )
            );
        }
    }

    /**
     * Assert that two arrays match (ignoring order).
     *
     * @param array<mixed> $expected
     * @param array<mixed> $actual
     */
    protected function assertArraysMatch(array $expected, array $actual, string $message = ''): void
    {
        sort($expected);
        sort($actual);

        $this->assertEquals($expected, $actual, $message);
    }
}
