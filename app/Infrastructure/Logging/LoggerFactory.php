<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * Factory for creating PSR-3 compliant logger instances.
 *
 * Creates Monolog loggers configured with:
 * - JSON formatting for AI-readable logs
 * - Rotating file handler (30 days retention)
 * - CQRS context processor
 * - Correlation ID processor (automatic injection)
 * - Framework-agnostic log path resolution
 *
 * Framework-Agnostic Design:
 * - Uses WRITEPATH constant when defined (CodeIgniter context)
 * - Falls back to __DIR__ navigation when not (standalone/testing)
 * - Logs to: writable/logs/app.json (with date rotation)
 *
 * Example usage in CQRS handlers:
 * <code>
 * $logger = LoggerFactory::create('cookie.command.create');
 * $logger->info('Creating cookie', ['name' => $cookieName]);
 * </code>
 */
final class LoggerFactory
{
    private const int DEFAULT_MAX_FILES = 30;
    private const string LOG_FILENAME = 'app.json';

    /**
     * Create a logger instance with CQRS context.
     *
     * @param string $channel Logger channel name (e.g., 'cookie.command.create')
     * @param Level $level Minimum log level (default: INFO)
     * @return Logger PSR-3 compliant logger instance
     */
    public static function create(string $channel, Level $level = Level::Info): Logger
    {
        $logger = new Logger($channel);

        $handler = self::createRotatingFileHandler($level);
        $logger->pushHandler($handler);

        $processor = self::createCqrsContextProcessor();
        $logger->pushProcessor($processor);

        $correlationProcessor = self::createCorrelationIdProcessor();
        $logger->pushProcessor($correlationProcessor);

        return $logger;
    }

    /**
     * Get the log directory path.
     *
     * Uses WRITEPATH constant if defined (CodeIgniter context),
     * otherwise falls back to __DIR__ navigation (standalone context).
     *
     * @return string Absolute path to logs directory with trailing slash
     */
    private static function getLogDirectory(): string
    {
        if (defined('WRITEPATH')) {
            return WRITEPATH . 'logs/';
        }

        return __DIR__ . '/../../../writable/logs/';
    }

    /**
     * Create rotating file handler with JSON formatter.
     *
     * @param Level $level Minimum log level
     * @return RotatingFileHandler Configured handler instance
     */
    private static function createRotatingFileHandler(Level $level): RotatingFileHandler
    {
        $logPath = self::getLogDirectory() . self::LOG_FILENAME;

        $handler = new RotatingFileHandler(
            filename: $logPath,
            maxFiles: self::DEFAULT_MAX_FILES,
            level: $level,
            bubble: true,
            filePermission: 0644,
        );

        $formatter = new JsonFormatter(
            batchMode: JsonFormatter::BATCH_MODE_NEWLINES,
            appendNewline: true,
        );

        $handler->setFormatter($formatter);

        return $handler;
    }

    /**
     * Create CQRS context processor.
     *
     * Extracts domain, command, query, and event names from channel.
     * Channel format: {domain}.{type}.{operation}
     * Example: 'cookie.command.create' -> domain: cookie, command: create
     *
     * @return callable(LogRecord): LogRecord Processor callable
     */
    private static function createCqrsContextProcessor(): callable
    {
        return static function (LogRecord $record): LogRecord {
            $channel = $record->channel;

            if ($channel === '') {
                return $record;
            }

            $parts = explode('.', $channel);

            if (count($parts) < 2) {
                return $record;
            }

            $context = [
                'domain' => $parts[0],
            ];

            if (isset($parts[1])) {
                $type = $parts[1];
                $operation = $parts[2] ?? null;

                match ($type) {
                    'command' => $context['command'] = $operation,
                    'query' => $context['query'] = $operation,
                    'event' => $context['event'] = $operation,
                    default => null,
                };
            }

            $filteredContext = array_filter($context, static fn(?string $value): bool => $value !== null);

            return $record->with(extra: array_merge($record->extra, ['cqrs' => $filteredContext]));
        };
    }

    /**
     * Create correlation ID processor.
     *
     * Automatically injects correlation_id into every log entry's extra field.
     * Uses CorrelationIdService::get() to retrieve the current correlation ID.
     *
     * @return callable(LogRecord): LogRecord Processor callable
     */
    private static function createCorrelationIdProcessor(): callable
    {
        return static function (LogRecord $record): LogRecord {
            $correlationId = CorrelationIdService::get();

            return $record->with(extra: array_merge($record->extra, ['correlation_id' => $correlationId]));
        };
    }
}
