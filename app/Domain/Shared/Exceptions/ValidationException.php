<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use InvalidArgumentException;

/**
 * Exception thrown when data validation fails.
 *
 * This exception represents validation errors at the domain level, typically
 * when input data does not conform to expected formats, ranges, or constraints.
 *
 * Validation vs Domain Exceptions:
 * - ValidationException: Data format/constraint violations (e.g., "email must be valid")
 * - DomainException: Business rule violations (e.g., "cannot delete active subscription")
 *
 * Why InvalidArgumentException:
 * Validation errors represent invalid arguments passed to domain objects,
 * making InvalidArgumentException the most semantically correct base class.
 *
 * Usage Example:
 * ```php
 * if (strlen($name) < 3) {
 *     throw ValidationException::fieldTooShort('name', 3, strlen($name));
 * }
 * ```
 *
 * @package App\Domain\Shared\Exceptions
 */
class ValidationException extends InvalidArgumentException
{
    /**
     * Validation errors grouped by field name.
     *
     * @var array<string, array<string>> Format: ['fieldName' => ['error1', 'error2']]
     */
    private array $errors = [];

    private int $errorCode = 0;

    /**
     * Create a new validation exception.
     *
     * @param string $message Human-readable error message
     * @param array<string, array<string>> $errors Associative array of field errors
     * @param int $errorCode Domain-specific error code (separate from HTTP status)
     * @param int $code PHP exception code (default: 0)
     */
    public function __construct(string $message, array $errors = [], int $errorCode = 0, int $code = 0)
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
        $this->errorCode = $errorCode;
    }

    /**
     * Get all validation errors.
     *
     * @return array<string, array<string>> Errors grouped by field name
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check if there are any validation errors.
     *
     * @return bool True if errors exist
     */
    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    /**
     * Get domain-specific error code.
     *
     * @return int Error code
     */
    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * Create exception for required field missing.
     *
     * @param string $fieldName Name of the required field
     * @param int $errorCode Domain-specific error code
     */
    public static function required(string $fieldName, int $errorCode = 0): self
    {
        return new self(
            sprintf('The field "%s" is required', $fieldName),
            [$fieldName => ['This field is required']],
            $errorCode
        );
    }

    /**
     * Create exception for field value too short.
     *
     * @param string $fieldName Name of the field
     * @param int $minLength Minimum required length
     * @param int $actualLength Actual length provided
     * @param int $errorCode Domain-specific error code
     */
    public static function fieldTooShort(string $fieldName, int $minLength, int $actualLength, int $errorCode = 0): self
    {
        return new self(
            sprintf('The field "%s" must be at least %d characters (got %d)', $fieldName, $minLength, $actualLength),
            [$fieldName => [sprintf('Must be at least %d characters', $minLength)]],
            $errorCode
        );
    }

    /**
     * Create exception for field value too long.
     *
     * @param string $fieldName Name of the field
     * @param int $maxLength Maximum allowed length
     * @param int $actualLength Actual length provided
     * @param int $errorCode Domain-specific error code
     */
    public static function fieldTooLong(string $fieldName, int $maxLength, int $actualLength, int $errorCode = 0): self
    {
        return new self(
            sprintf('The field "%s" must not exceed %d characters (got %d)', $fieldName, $maxLength, $actualLength),
            [$fieldName => [sprintf('Must not exceed %d characters', $maxLength)]],
            $errorCode
        );
    }

    /**
     * Create exception for invalid numeric range.
     *
     * @param string $fieldName Name of the field
     * @param float|int $min Minimum allowed value
     * @param float|int $max Maximum allowed value
     * @param float|int $actual Actual value provided
     * @param int $errorCode Domain-specific error code
     */
    public static function outOfRange(string $fieldName, float|int $min, float|int $max, float|int $actual, int $errorCode = 0): self
    {
        return new self(
            sprintf('The field "%s" must be between %s and %s (got %s)', $fieldName, $min, $max, $actual),
            [$fieldName => [sprintf('Must be between %s and %s', $min, $max)]],
            $errorCode
        );
    }

    /**
     * Create exception for value below minimum.
     *
     * @param string $fieldName Name of the field
     * @param float|int $min Minimum allowed value
     * @param float|int $actual Actual value provided
     * @param int $errorCode Domain-specific error code
     */
    public static function tooSmall(string $fieldName, float|int $min, float|int $actual, int $errorCode = 0): self
    {
        return new self(
            sprintf('The field "%s" must be at least %s (got %s)', $fieldName, $min, $actual),
            [$fieldName => [sprintf('Must be at least %s', $min)]],
            $errorCode
        );
    }

    /**
     * Create exception for invalid format.
     *
     * @param string $fieldName Name of the field
     * @param string $expectedFormat Description of expected format
     * @param int $errorCode Domain-specific error code
     */
    public static function invalidFormat(string $fieldName, string $expectedFormat, int $errorCode = 0): self
    {
        return new self(
            sprintf('The field "%s" has invalid format. Expected: %s', $fieldName, $expectedFormat),
            [$fieldName => [sprintf('Invalid format. Expected: %s', $expectedFormat)]],
            $errorCode
        );
    }

    /**
     * Create exception with multiple field errors.
     *
     * @param array<string, array<string>> $errors Errors grouped by field name
     * @param int $errorCode Domain-specific error code
     */
    public static function withErrors(array $errors, int $errorCode = 0): self
    {
        $fieldNames = implode(', ', array_keys($errors));
        $totalErrors = array_sum(array_map('count', $errors));

        $message = sprintf(
            'Validation failed for field(s): %s (%d error(s))',
            $fieldNames,
            $totalErrors
        );

        return new self($message, $errors, $errorCode);
    }
}
