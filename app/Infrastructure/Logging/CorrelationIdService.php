<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

/**
 * Correlation ID Service
 *
 * Manages correlation IDs for request tracing across distributed systems.
 * Provides thread-safe storage for the current request's correlation ID.
 *
 * Usage:
 * - Automatically generates UUID v4 on first access
 * - Can be manually set for propagating external correlation IDs
 * - Should be cleared between test cases to avoid leakage
 *
 * Example:
 * ```php
 * $id = CorrelationIdService::get(); // Gets or generates
 * CorrelationIdService::set('custom-id-123'); // Manual override
 * CorrelationIdService::clear(); // Reset for testing
 * ```
 *
 * @package App\Infrastructure\Logging
 */
final class CorrelationIdService
{
    /**
     * Current correlation ID for this request lifecycle
     *
     * @var string|null
     */
    private static ?string $correlationId = null;

    /**
     * Generates a new UUID v4 correlation ID
     *
     * Uses ramsey/uuid if available, otherwise falls back to uniqid().
     * Format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx (UUID v4)
     *
     * @return string New UUID v4 correlation ID
     */
    public static function generate(): string
    {
        if (class_exists(\Ramsey\Uuid\Uuid::class)) {
            return \Ramsey\Uuid\Uuid::uuid4()->toString();
        }

        return self::generateFallbackUuid();
    }

    /**
     * Gets the current correlation ID
     *
     * Returns existing correlation ID or generates a new one if null.
     * Thread-safe for single request lifecycle.
     *
     * @return string Current or newly generated correlation ID
     */
    public static function get(): string
    {
        if (self::$correlationId === null) {
            self::$correlationId = self::generate();
        }

        return self::$correlationId;
    }

    /**
     * Manually sets the correlation ID
     *
     * Useful for propagating correlation IDs from upstream services
     * or when receiving correlation ID from request headers.
     *
     * @param string $id Correlation ID to set
     * @return void
     */
    public static function set(string $id): void
    {
        self::$correlationId = $id;
    }

    /**
     * Clears the current correlation ID
     *
     * Resets correlation ID to null. Primarily used for testing
     * to prevent correlation ID leakage between test cases.
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$correlationId = null;
    }

    /**
     * Generates a fallback UUID v4 when ramsey/uuid is not available
     *
     * Creates a valid UUID v4 format using PHP's random_bytes().
     * Format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     * - Version: 4 (random)
     * - Variant: RFC 4122
     *
     * @return string UUID v4 formatted string
     */
    private static function generateFallbackUuid(): string
    {
        $data = random_bytes(16);

        // Set version (4) in bits 12-15 of time_hi_and_version
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);

        // Set variant (RFC 4122) in bits 6-7 of clock_seq_hi_and_reserved
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
