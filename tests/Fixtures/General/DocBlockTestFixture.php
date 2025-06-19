<?php

namespace PhpMcp\Server\Tests\Fixtures\General;

/**
 * A stub class for testing DocBlock parsing.
 */
class DocBlockTestFixture
{
    /**
     * Simple summary line.
     */
    public function methodWithSummaryOnly(): void
    {
    }

    /**
     * Summary line here.
     *
     * This is a longer description spanning
     * multiple lines.
     * It might contain *markdown* or `code`.
     *
     * @since 1.0
     */
    public function methodWithSummaryAndDescription(): void
    {
    }

    /**
     * Method with various parameter tags.
     *
     * @param string $param1 Description for string param.
     * @param int|null $param2 Description for nullable int param.
     * @param bool $param3
     * @param $param4 Missing type.
     * @param array<string, mixed> $param5 Array description.
     * @param \stdClass $param6 Object param.
     */
    public function methodWithParams(string $param1, ?int $param2, bool $param3, $param4, array $param5, \stdClass $param6): void
    {
    }

    /**
     * Method with return tag.
     *
     * @return string The result of the operation.
     */
    public function methodWithReturn(): string
    {
        return '';
    }

    /**
     * Method with multiple tags.
     *
     * @param float $value The value to process.
     * @return bool Status of the operation.
     * @throws \RuntimeException If processing fails.
     * @deprecated Use newMethod() instead.
     * @see \PhpMcp\Server\Tests\Fixtures\General\DocBlockTestFixture::newMethod()
     */
    public function methodWithMultipleTags(float $value): bool
    {
        return true;
    }

    /**
     * Malformed docblock - missing closing
     */
    public function methodWithMalformedDocBlock(): void
    {
    }

    public function methodWithNoDocBlock(): void
    {
    }

    // Some other method needed for a @see tag perhaps
    public function newMethod(): void
    {
    }
}
