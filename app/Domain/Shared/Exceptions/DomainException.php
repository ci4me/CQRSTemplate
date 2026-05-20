<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * Base exception for all domain-related errors.
 *
 * This exception should be thrown when a business rule or domain invariant
 * is violated. It represents errors that are part of the business domain
 * and should be handled by the application layer.
 *
 * Examples of domain violations:
 * - Attempting to set a negative price
 * - Violating unique constraints defined by business rules
 * - Invalid state transitions
 * - Business logic failures
 *
 * Why RuntimeException:
 * Domain exceptions are unchecked exceptions because they represent
 * programming errors or invalid business operations that should be
 * prevented by the application layer.
 *
 * Error Codes:
 * Each exception can carry a domain-specific error code for:
 * - Precise error identification
 * - Client-side error handling
 * - Monitoring and alerting
 * - Log analysis and filtering
 *
 * @package App\Domain\Shared\Exceptions
 */
class DomainException extends RuntimeException
{
    /** @var int */
    private int $errorCode = 0;

    /**
     * Create a new domain exception.
     *
     * @param string $message   Human-readable error message explaining the domain violation
     * @param int    $errorCode Domain-specific error code (separate from HTTP status)
     * @param int    $code      PHP exception code (default: 0)
     */
    public function __construct(string $message, int $errorCode = 0, int $code = 0)
    {
        parent::__construct($message, $code);
        $this->errorCode = $errorCode;
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
     * Create exception for invalid entity state.
     *
     * @param string $entityName Name of the entity (e.g., "Cookie", "User")
     * @param string $reason     Why the state is invalid
     * @param int    $errorCode  Domain-specific error code
     * @return self
     */
    public static function invalidState(string $entityName, string $reason, int $errorCode = 0): self
    {
        return new self(
            sprintf('Invalid state for %s: %s', $entityName, $reason),
            $errorCode
        );
    }

    /**
     * Create exception for business rule violation.
     *
     * @param string $ruleName  Name of the violated business rule
     * @param string $details   Additional details about the violation
     * @param int    $errorCode Domain-specific error code
     * @return self
     */
    public static function businessRuleViolation(string $ruleName, string $details, int $errorCode = 0): self
    {
        return new self(
            sprintf('Business rule "%s" violated: %s', $ruleName, $details),
            $errorCode
        );
    }

    /**
     * Create exception for entity not found scenarios.
     *
     * @param string     $entityName Name of the entity that was not found
     * @param int|string $identifier The identifier that was searched for
     * @param int        $errorCode  Domain-specific error code
     * @return self
     */
    public static function notFound(string $entityName, int|string $identifier, int $errorCode = 0): self
    {
        return new self(
            sprintf('%s with identifier "%s" not found', $entityName, $identifier),
            $errorCode
        );
    }

    /**
     * Create exception for an optimistic-locking conflict — caller's `version`
     * did not match the row's current `version`, meaning the row was modified
     * by someone else between read and write.
     *
     * @param string     $entityName      Name of the entity that lost the race
     * @param int|string $identifier      ID of the row
     * @param int        $expectedVersion Version the caller held
     * @param int        $actualVersion   Current version in the database
     * @param int        $errorCode       Optional domain error code
     * @return self
     */
    public static function concurrentModification(
        string $entityName,
        int|string $identifier,
        int $expectedVersion,
        int $actualVersion,
        int $errorCode = 0
    ): self {
        return new self(
            sprintf(
                '%s #%s was modified by someone else (expected version %d, got %d). Reload and retry.',
                $entityName,
                $identifier,
                $expectedVersion,
                $actualVersion
            ),
            $errorCode
        );
    }
}
