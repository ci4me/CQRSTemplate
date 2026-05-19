<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Logging Configuration
 *
 * Configures logging behavior for the application, including query logging,
 * performance monitoring, correlation IDs, and business metrics.
 *
 * Usage Example:
 * ```php
 * $config = config('Logging');
 * $isBusinessMetricsEnabled = $config->businessMetricsEnabled; // true
 * $slowQueryMs = $config->slowQueryThresholdMs; // 100
 * ```
 *
 * Environment Variable Overrides:
 * - QUERY_LOGGING_LEVEL: Override queryLoggingLevel ('all'|'errors'|'slow'|'sampling')
 * - SLOW_QUERY_THRESHOLD_MS: Override slowQueryThresholdMs (integer)
 * - SAMPLING_RATE: Override samplingRate (float between 0.0 and 1.0)
 * - CORRELATION_ID_ENABLED: Override correlationIdEnabled ('true'|'false')
 * - BUSINESS_METRICS_ENABLED: Override businessMetricsEnabled ('true'|'false')
 */
final class Logging extends BaseConfig
{
    /**
     * Query Logging Level
     *
     * Controls which database queries are logged:
     * - 'all': Log every single database query (high overhead, use only for debugging)
     * - 'errors': Log only failed queries (recommended for production)
     * - 'slow': Log only queries exceeding slowQueryThresholdMs (performance monitoring)
     * - 'sampling': Log random sample of queries based on samplingRate (profiling)
     *
     * Default: 'errors' (production-safe, minimal overhead)
     */
    public string $queryLoggingLevel = 'errors';

    /**
     * Slow Query Threshold (milliseconds)
     *
     * Queries taking longer than this threshold will be logged when
     * queryLoggingLevel is set to 'slow'.
     *
     * Typical values:
     * - 50ms: Aggressive performance monitoring
     * - 100ms: Balanced (default)
     * - 500ms: Only capture severely slow queries
     * - 1000ms: Only capture critical performance issues
     *
     * Default: 100 (log queries taking more than 100ms)
     */
    public int $slowQueryThresholdMs = 100;

    /**
     * Sampling Rate (0.0 to 1.0)
     *
     * Percentage of queries to randomly log when queryLoggingLevel is 'sampling'.
     *
     * Examples:
     * - 0.01 = 1% of queries (default, ~100 queries per 10,000)
     * - 0.05 = 5% of queries
     * - 0.10 = 10% of queries
     * - 1.00 = 100% of queries (equivalent to 'all' mode)
     *
     * Default: 0.01 (1% sampling rate)
     */
    public float $samplingRate = 0.01;

    /**
     * Correlation ID Enabled
     *
     * When enabled, adds a unique correlation ID to each request/job,
     * allowing you to trace all log entries related to a single operation.
     *
     * Benefits:
     * - Trace errors across multiple services
     * - Group related log entries in log aggregators (ELK, Datadog, etc.)
     * - Debug complex request flows
     *
     * Format: UUID v4 (e.g., "550e8400-e29b-41d4-a716-446655440000")
     *
     * Default: true (recommended for all environments)
     */
    public bool $correlationIdEnabled = true;

    /**
     * Business Metrics Enabled
     *
     * When enabled, logs business-level events and metrics for analytics:
     * - Command executions (CreateCookie, UpdateOrder, etc.)
     * - Query executions (GetCookieById, GetAllOrders, etc.)
     * - Event dispatches (CookieCreated, OrderShipped, etc.)
     * - Custom business metrics
     *
     * Use cases:
     * - Track feature usage
     * - Monitor business KPIs
     * - Generate analytics dashboards
     * - Audit trail for compliance
     *
     * Default: true (enables business observability)
     */
    public bool $businessMetricsEnabled = true;
}
