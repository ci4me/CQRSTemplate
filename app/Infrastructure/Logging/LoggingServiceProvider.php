<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Psr\Log\LoggerInterface;

/**
 * Service Provider for Logging Infrastructure.
 *
 * This class provides factory methods for creating PSR-3 compliant logger instances
 * that can be injected into CQRS handlers and other application components.
 *
 * The logger is created using LoggerFactory with CQRS-aware context processing
 * and JSON formatting for AI-readable logs.
 *
 * Usage in CQRS handlers:
 * <code>
 * public function __construct(
 *     private readonly LoggerInterface $logger,
 * ) {}
 * </code>
 *
 * Registration in Services.php:
 * <code>
 * public static function logger(bool $getShared = true): LoggerInterface
 * {
 *     if ($getShared) {
 *         return static::getSharedInstance('logger');
 *     }
 *
 *     return LoggingServiceProvider::createLogger('app');
 * }
 * </code>
 *
 * @package App\Infrastructure\Logging
 */
final readonly class LoggingServiceProvider
{
    /**
     * Create a logger instance for the application.
     *
     * Creates a Monolog logger configured with:
     * - JSON formatting for AI-readable logs
     * - Rotating file handler (30 days retention)
     * - CQRS context processor for domain/command/query/event extraction
     *
     * @param string $channel Logger channel name (e.g., 'app', 'cookie.command.create')
     * @return LoggerInterface PSR-3 compliant logger instance
     */
    public static function createLogger(string $channel = 'app'): LoggerInterface
    {
        return LoggerFactory::create($channel);
    }

    /**
     * Create a domain-specific logger instance.
     *
     * Helper method for creating loggers with domain-specific channels.
     * Channel format: {domain}.{type}.{operation}
     *
     * @param string $domain Domain name (e.g., 'cookie')
     * @param string $type Operation type (e.g., 'command', 'query', 'event')
     * @param string $operation Operation name (e.g., 'create', 'update', 'delete')
     * @return LoggerInterface PSR-3 compliant logger instance
     */
    public static function createDomainLogger(
        string $domain,
        string $type,
        string $operation
    ): LoggerInterface {
        $channel = sprintf('%s.%s.%s', $domain, $type, $operation);
        return LoggerFactory::create($channel);
    }
}
