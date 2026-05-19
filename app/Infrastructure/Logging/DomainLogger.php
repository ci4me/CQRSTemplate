<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Psr\Log\LoggerInterface;

/**
 * Static utility for domain logging.
 *
 * Provides centralized logging for:
 * - Validation failures in value objects
 * - Business rule violations in entities
 * - Domain-level warnings and errors
 *
 * Uses lazy initialization to avoid creating logger
 * instances when logging is not needed.
 *
 * Thread-Safe:
 * Uses static lazy initialization pattern for singleton logger.
 *
 * Usage Example:
 * ```php
 * DomainLogger::logValidation('Cookie', 'CookieName', [
 *     'attempted_value' => $invalidName,
 *     'validation_rule' => 'required',
 *     'error_code' => ErrorCodes::COOKIE_VALIDATION_NAME,
 * ]);
 * ```
 *
 * @package App\Infrastructure\Logging
 */
final class DomainLogger
{
    private static ?LoggerInterface $logger = null;

    /**
     * Get shared logger instance (lazy initialization).
     *
     * @return LoggerInterface Logger instance
     */
    private static function getLogger(): LoggerInterface
    {
        if (self::$logger === null) {
            self::$logger = LoggerFactory::create('domain.validation');
        }

        return self::$logger;
    }

    /**
     * Log validation failure before throwing exception.
     *
     * @param string $domain Domain name (e.g., 'Cookie')
     * @param string $valueObject Value object class name (e.g., 'CookieName')
     * @param array<string, mixed> $context Additional context (attempted_value, validation_rule, error_code)
     */
    public static function logValidation(string $domain, string $valueObject, array $context): void
    {
        self::getLogger()->warning('Validation failure', array_merge([
            'domain' => $domain,
            'value_object' => $valueObject,
        ], $context));
    }

    /**
     * Log business rule violation before throwing exception.
     *
     * @param string $domain Domain name (e.g., 'Cookie')
     * @param string $entity Entity class name (e.g., 'Cookie')
     * @param array<string, mixed> $context Additional context (business_rule, current_state, error_code)
     */
    public static function logBusinessRule(string $domain, string $entity, array $context): void
    {
        self::getLogger()->error('Business rule violation', array_merge([
            'domain' => $domain,
            'entity' => $entity,
        ], $context));
    }

    /**
     * Reset logger instance (for testing).
     */
    public static function reset(): void
    {
        self::$logger = null;
    }
}
