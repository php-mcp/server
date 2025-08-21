<?php

declare(strict_types=1);

namespace PhpMcp\Server\Exception;

final class ValidationException extends \Exception
{
    public static function invalidSchemaDefinition(\Throwable $previous): self
    {
        return new self(
            errors: [
                [
                    'pointer' => '',
                    'keyword' => 'internal',
                    'message' => 'Invalid schema definition provided (JSON error).',
                ],
            ],
            previous: $previous,
        );
    }

    public static function invalidSchemaStructure(\Throwable $previous): self
    {
        return new self(
            errors: [['pointer' => '', 'keyword' => 'internal', 'message' => $previous->getMessage()]],
            previous: $previous,
        );
    }

    public static function internalError(\Throwable $previous): self
    {
        return new self(
            errors: [
                [
                    'pointer' => '',
                    'keyword' => 'internal',
                    'message' => 'Schema validation process failed: ' . $previous->getMessage(),
                ],
            ],
            previous: $previous,
        );
    }

    /**
     * @param list<array{pointer: string, keyword: string, message: string}> $errors Array of validation errors, empty if valid.
     */
    public function __construct(public readonly array $errors, ?\Throwable $previous = null)
    {
        parent::__construct(message: 'Validation errors', code: 422, previous: $previous);
    }

    public function buildMessage(string $toolName): string
    {
        $errorMessages = [];

        foreach ($this->errors as $errorDetail) {
            $pointer = $errorDetail['pointer'] ?? '';
            $message = $errorDetail['message'] ?? 'Unknown validation error';
            $errorMessages[] = ($pointer !== '/' && $pointer !== '' ? "Property '{$pointer}': " : '') . $message;
        }

        $summaryMessage = sprintf(
            "Invalid parameters for tool '{$toolName}': %s",
            implode('; ', array_slice($errorMessages, 0, 3)),
        );

        if (count($errorMessages) > 3) {
            $summaryMessage .= '; ...and more errors.';
        }

        return $summaryMessage;
    }
}
