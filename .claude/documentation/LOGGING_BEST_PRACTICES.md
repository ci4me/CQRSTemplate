# Logging Best Practices

**Last Updated:** 2025-10-22
**Status:** Production Ready
**Cookie Domain:** ✅ Fully Implemented

This document provides comprehensive guidelines for implementing logging across all domains in the CQRS template project.

## Table of Contents

1. [When to Log](#when-to-log)
2. [What to Log](#what-to-log)
3. [Log Levels](#log-levels)
4. [Context Requirements](#context-requirements)
5. [Error Codes](#error-codes)
6. [Correlation IDs](#correlation-ids)
7. [Query Logging Configuration](#query-logging-configuration)
8. [Performance Considerations](#performance-considerations)
9. [Implementation Patterns](#implementation-patterns)
10. [Testing Logging](#testing-logging)

---

## When to Log

### Command Handlers (Write Operations)

**✅ ALWAYS log:**
- Command start (with input parameters)
- Command success (with duration_ms)
- Command errors (with error_code and stack trace)

**Example:**
```php
$startTime = microtime(true);
$this->logger->info('Creating cookie', [
    'domain' => 'Cookie',
    'command' => 'CreateCookieCommand',
    'cookie_name' => $command->name,
    'price' => $command->price,
]);

try {
    // Business logic...

    $duration = round((microtime(true) - $startTime) * 1000, 2);
    $this->logger->info('Cookie created successfully', [
        'domain' => 'Cookie',
        'command' => 'CreateCookieCommand',
        'cookie_id' => $cookieId,
        'duration_ms' => $duration,
    ]);
} catch (\Throwable $e) {
    $this->logger->error('Failed to create cookie', [
        'domain' => 'Cookie',
        'command' => 'CreateCookieCommand',
        'error_code' => $this->determineErrorCode($e),
        'exception' => $e->getMessage(),
        'exception_class' => $e::class,
    ]);
    throw $e;
}
```

### Query Handlers (Read Operations)

**✅ LOG BASED ON CONFIGURATION:**
- Use `Config\Logging->queryLoggingLevel` to control verbosity
- Always log slow queries (>threshold)
- Always log errors

**Four Logging Levels:**
1. **all** - Log every query execution
2. **errors** - Log only failed queries
3. **slow** - Log only slow queries (>threshold)
4. **sampling** - Log random sample (e.g., 1%)

**Example:**
```php
$startTime = microtime(true);
$result = $this->repository->findById($query->id);
$duration = round((microtime(true) - $startTime) * 1000, 2);

if ($this->shouldLog($this->loggingConfig, $duration, $result)) {
    $this->logger->info('Query executed', [
        'domain' => 'Cookie',
        'query' => 'GetCookieByIdQuery',
        'cookie_id' => $query->id,
        'result' => $result === null ? 'not_found' : 'found',
        'duration_ms' => $duration,
        'slow_query' => $duration > $this->loggingConfig->slowQueryThresholdMs,
    ]);
}
```

### Event Handlers

**✅ ALWAYS log:**
- Event received (with event data)
- Event processed (with side effects performed)

**Example:**
```php
$this->logger->info('Cookie created', [
    'domain' => 'Cookie',
    'event' => 'CookieCreatedEvent',
    'cookie_id' => $event->cookieId,
    'cookie_name' => $event->cookieName,
]);
```

### Repository (Data Access)

**✅ LOG:**
- Database errors (constraint violations, connection errors)
- Slow queries (exceeding threshold)
- Business metrics (low stock, high-value operations)

**Example:**
```php
try {
    $result = $this->model->insert($data);

    // Check business metrics
    if ($data['stock'] < 10) {
        $this->logBusinessMetric('low_stock_alert', [
            'cookie_id' => $cookieId,
            'stock' => $data['stock'],
        ]);
    }
} catch (\Throwable $e) {
    $this->logger->error('Database error', [
        'domain' => 'Cookie',
        'repository' => 'CookieRepository',
        'method' => 'save',
        'error_code' => ErrorCodes::COOKIE_REPOSITORY_SAVE_FAILED,
        'exception' => $e->getMessage(),
    ]);
    throw $e;
}
```

### Value Objects & Entities

**✅ THROW EXCEPTIONS FROM DOMAIN OBJECTS:**
- Validation failures (value objects) — throw ValidationException
- Business rule violations (entities) — throw DomainException
- Logging is handled by the command handler's catch block, not inside domain objects

**Domain objects stay free of infrastructure dependencies:**
```php
// Value Object — throw exception (handler logs it)
if ($normalized === '') {
    throw ValidationException::required('name', ErrorCodes::COOKIE_VALIDATION_NAME);
}

// Entity — throw exception (handler logs it)
if ($newStock < 0) {
    throw DomainException::businessRuleViolation(
        'Stock cannot be negative',
        sprintf('Attempted to decrease stock by %d when only %d available', $quantity, $this->stock),
        ErrorCodes::COOKIE_BUSINESS_RULE_STOCK_NEGATIVE
    );
}
```

---

## What to Log

### Minimum Required Context Fields

**All log entries MUST include:**
- `domain` - Domain name (e.g., "Cookie", "Order")
- `{type}` - One of: command, query, event, repository, value_object, entity
- `{type}_name` - Specific name (e.g., "CreateCookieCommand")

**Additional recommended fields:**
- `duration_ms` - Execution duration
- `error_code` - Standardized error code (for errors)
- `correlation_id` - Request tracing (auto-injected via processor)

### Domain-Specific Context

**Commands:**
- Input parameters (name, price, stock)
- Entity IDs
- Changes made (old/new values for updates)

**Queries:**
- Query parameters (id, page, searchTerm)
- Result count
- Whether result was found/not found

**Events:**
- Event data (entity ID, changed fields)
- Timestamp of original action

**Repository:**
- Method name (save, delete, findById)
- Entity ID
- Query details (filters, limits)

**Validation:**
- Attempted value
- Validation rule violated
- Error code

**Business Rules:**
- Rule name
- Current state
- Attempted action
- Why it failed

---

## Log Levels

Follow PSR-3 log levels strictly:

### DEBUG
**Use for:** Detailed debug information useful during development

**Example:** "Query builder constructed with filters: ..."

### INFO
**Use for:** Interesting events (command execution, query results, event processing)

**Example:** "Cookie created successfully", "Query executed"

### WARNING
**Use for:** Exceptional occurrences that are not errors (validation failures, deprecated usage)

**Example:** "Validation failure", "Cookie name too short"

### ERROR
**Use for:** Runtime errors that don't require immediate action (business rule violations, database errors)

**Example:** "Business rule violation", "Database connection failed"

### CRITICAL
**Use for:** Critical conditions (system component unavailable, unexpected exceptions)

**Example:** "Database unavailable", "Event dispatcher crashed"

---

## Context Requirements

### Structured Context (Required)

Always use associative arrays for context:

```php
// ✅ Good
$logger->info('Cookie created', [
    'domain' => 'Cookie',
    'cookie_id' => $id,
    'cookie_name' => $name,
]);

// ❌ Bad - String interpolation
$logger->info("Cookie {$name} created with ID {$id}");
```

### Context Field Naming

**Use snake_case for all context keys:**
```php
'cookie_id'       // ✅
'cookieId'        // ❌
'duration_ms'     // ✅
'durationMs'      // ❌
```

### Sensitive Data

**❌ NEVER log:**
- Passwords (even hashed)
- Credit card numbers
- API keys
- Personal identifiable information (PII) without consent

**✅ Safe to log:**
- Entity IDs
- Public names
- Prices
- Counts/quantities

---

## Error Codes

### Purpose

Error codes enable:
- Automated alerting (trigger on specific codes)
- Error categorization and metrics
- AI-based log analysis
- Client-side error handling

### Registry Structure

**Cookie domain error codes (`ErrorCodes.php`):**
```php
// Validation errors (100-199)
public const int COOKIE_VALIDATION_NAME = 101;
public const int COOKIE_VALIDATION_PRICE = 102;

// Not found errors (200-299)
public const int COOKIE_NOT_FOUND = 201;

// Business rule violations (300-399)
public const int COOKIE_BUSINESS_RULE_STOCK_NEGATIVE = 301;

// Repository errors (400-499)
public const int COOKIE_REPOSITORY_SAVE_FAILED = 401;
```

### Usage in Exceptions

**All custom exceptions MUST accept error codes:**
```php
// DomainException
throw DomainException::businessRuleViolation(
    'Stock cannot be negative',
    'details',
    ErrorCodes::COOKIE_BUSINESS_RULE_STOCK_NEGATIVE
);

// ValidationException
throw ValidationException::required(
    'name',
    ErrorCodes::COOKIE_VALIDATION_NAME
);
```

### Logging Error Codes

**Always include error_code in error logs:**
```php
$this->logger->error('Validation failed', [
    'domain' => 'Cookie',
    'error_code' => ErrorCodes::COOKIE_VALIDATION_NAME,
    'attempted_value' => $value,
]);
```

---

## Correlation IDs

### Purpose

Correlation IDs enable distributed tracing across:
- Multiple handlers (command → event → another command)
- Repository calls
- External API requests
- Background jobs

### Auto-Injection

**CorrelationIdProcessor automatically adds correlation_id to ALL log entries:**
```json
{
  "message": "Cookie created",
  "context": {"domain": "Cookie", "cookie_id": 123},
  "extra": {
    "correlation_id": "3fd536da-71a6-4e85-8b62-769d4c86b99b"
  }
}
```

### Manual Usage

```php
// Generate new correlation ID
$correlationId = CorrelationIdService::generate();

// Get current correlation ID
$currentId = CorrelationIdService::get();

// Set specific correlation ID (e.g., from HTTP header)
CorrelationIdService::set($incomingCorrelationId);

// Clear for testing
CorrelationIdService::clear();
```

### Request Lifecycle

1. **HTTP Request arrives** → Generate correlation ID
2. **Command handler executes** → Logs include correlation ID
3. **Event dispatched** → Same correlation ID
4. **Event handler executes** → Same correlation ID
5. **Repository queries** → Same correlation ID

**Result:** Entire request traceable with single ID

---

## Query Logging Configuration

### Configuration File

**`app/Config/Logging.php`:**
```php
public string $queryLoggingLevel = 'errors'; // 'all'|'errors'|'slow'|'sampling'
public int $slowQueryThresholdMs = 100;
public float $samplingRate = 0.01; // 1%
public bool $correlationIdEnabled = true;
public bool $businessMetricsEnabled = true;
```

### Logging Levels Explained

| Level | Description | Use Case |
|-------|-------------|----------|
| **all** | Log every query | Development, debugging |
| **errors** | Log only failed queries | Production (recommended) |
| **slow** | Log only slow queries (>threshold) | Performance tuning |
| **sampling** | Log random sample (e.g., 1%) | High-traffic production |

### Implementation Pattern

```php
private function shouldLog(Config\Logging $config, float $duration, mixed $result): bool
{
    // Always log slow queries
    if ($duration > $config->slowQueryThresholdMs) {
        return true;
    }

    return match ($config->queryLoggingLevel) {
        'all' => true,
        'errors' => $result === null,
        'slow' => false, // Already checked above
        'sampling' => (mt_rand() / mt_getrandmax()) < $config->samplingRate,
        default => false
    };
}
```

### Performance Impact

| Level | Log Volume | Performance Overhead |
|-------|------------|---------------------|
| all | 100% | High (avoid in production) |
| errors | <1% | Negligible |
| slow | 1-5% | Very low |
| sampling (1%) | 1% | Very low |

---

## Performance Considerations

### Duration Tracking

**Always use microtime(true) for precision:**
```php
$startTime = microtime(true);
// ... operation ...
$duration = round((microtime(true) - $startTime) * 1000, 2); // ms with 2 decimals
```

### Lazy Initialization

**LoggerFactory uses lazy loading to avoid overhead:**
```php
private static function getLogger(): LoggerInterface
{
    if (self::$logger === null) {
        self::$logger = LoggerFactory::create('domain.validation');
    }
    return self::$logger;
}
```

### Context Size Limits

**Keep context arrays small (<10 fields):**
- Log only what you need
- Avoid logging entire objects (log IDs instead)
- Use references for large data

### Async Logging (Future)

Consider async handlers for high-traffic production:
```php
// Monolog AsyncHandler (example)
$handler = new AsyncHandler(
    new StreamHandler($logFile),
    $logLevel,
    $bubble = true
);
```

---

## Implementation Patterns

### Pattern 1: Command Handler Template

```php
final readonly class {Command}Handler implements CommandHandlerInterface
{
    public function __construct(
        private {Entity}Repository $repository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {}

    public function handle(CommandInterface $command): int
    {
        assert($command instanceof {Command}Command);

        $startTime = microtime(true);

        $this->logger->info('{Action description}', [
            'domain' => '{Domain}',
            'command' => '{Command}Command',
            'param1' => $command->param1,
        ]);

        try {
            // Business logic here

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->info('{Action} successful', [
                'domain' => '{Domain}',
                'command' => '{Command}Command',
                'entity_id' => $entityId,
                'duration_ms' => $duration,
            ]);

            return $entityId;

        } catch (\Throwable $e) {
            $this->logger->error('{Action} failed', [
                'domain' => '{Domain}',
                'command' => '{Command}Command',
                'error_code' => $this->determineErrorCode($e),
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            throw $e;
        }
    }

    private function determineErrorCode(\Throwable $e): int
    {
        if ($e instanceof DomainException || $e instanceof ValidationException) {
            return $e->getErrorCode();
        }
        return 0; // Unknown error
    }
}
```

### Pattern 2: Query Handler Template

```php
final readonly class {Query}Handler implements QueryHandlerInterface
{
    public function __construct(
        private {Entity}Repository $repository,
        private LoggerInterface $logger,
        private Config\Logging $loggingConfig
    ) {}

    public function handle(QueryInterface $query): mixed
    {
        assert($query instanceof {Query}Query);

        $startTime = microtime(true);
        $result = $this->repository->method($query->param);
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        if ($this->shouldLog($this->loggingConfig, $duration, $result)) {
            $this->logger->info('Query executed', [
                'domain' => '{Domain}',
                'query' => '{Query}Query',
                'param' => $query->param,
                'result' => $result === null ? 'not_found' : 'found',
                'duration_ms' => $duration,
                'slow_query' => $duration > $this->loggingConfig->slowQueryThresholdMs,
            ]);
        }

        return $result;
    }

    private function shouldLog(Config\Logging $config, float $duration, mixed $result): bool
    {
        if ($duration > $config->slowQueryThresholdMs) {
            return true;
        }

        return match ($config->queryLoggingLevel) {
            'all' => true,
            'errors' => $result === null,
            'slow' => false,
            'sampling' => (mt_rand() / mt_getrandmax()) < $config->samplingRate,
            default => false
        };
    }
}
```

### Pattern 3: Repository Logging with Traits

```php
class {Entity}Repository
{
    use RepositoryLogging;
    use BusinessMetricsLogging;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Config\Logging $loggingConfig
    ) {
        $this->model = new {Entity}Model();
    }

    public function save({Entity} $entity): int
    {
        return $this->logRepositoryOperation('save', function () use ($entity) {
            // Save logic
            return $entityId;
        }, ['entity_id' => $entity->getId()]);
    }
}
```

---

## Testing Logging

### Unit Tests

**Test that handlers log at expected points:**
```php
public function test_command_logs_success(): void
{
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->exactly(2)) // start + success
        ->method('info')
        ->with(
            $this->logicalOr(
                $this->equalTo('Creating cookie'),
                $this->equalTo('Cookie created successfully')
            ),
            $this->callback(function ($context) {
                return isset($context['domain'])
                    && $context['domain'] === 'Cookie';
            })
        );

    $handler = new CreateCookieHandler($repository, $eventDispatcher, $logger);
    $handler->handle($command);
}
```

### Integration Tests

**Verify logs are written to files:**
```php
public function test_logs_written_to_file(): void
{
    $logFile = WRITEPATH . 'logs/app-' . date('Y-m-d') . '.json';
    $beforeCount = count(file($logFile));

    // Execute operation that should log
    $this->commandBus->execute($command);

    $afterCount = count(file($logFile));
    $this->assertGreaterThan($beforeCount, $afterCount);
}
```

### Test Script

**Run comprehensive logging tests:**
```bash
php temp/test-comprehensive-logging.php
```

---

## Cookie Domain: Reference Implementation

The **Cookie domain** is the reference implementation for all logging patterns. Use it as a template when creating new domains.

**What's implemented:**
- ✅ All command handlers with duration tracking and error codes
- ✅ All query handlers with configurable logging
- ✅ All event handlers with PSR-3 logging
- ✅ Repository with database error logging, slow query detection, business metrics
- ✅ Value objects with validation logging
- ✅ Entity with business rule logging
- ✅ Correlation IDs automatically injected
- ✅ Error codes in all exceptions
- ✅ PHPStan Level 8 compliant
- ✅ 100% test coverage

**Files to reference:**
- `app/Domain/Cookie/Commands/*/Create*Handler.php`
- `app/Domain/Cookie/Queries/*/Get*Handler.php`
- `app/Domain/Cookie/Events/*/*EventHandler.php`
- `app/Infrastructure/Persistence/Repositories/CookieRepository.php`
- `app/Domain/Cookie/ValueObjects/CookieName.php`
- `app/Domain/Cookie/Entities/Cookie.php`
- `app/Domain/Cookie/ErrorCodes.php`

---

## Quick Checklist

When adding logging to a new domain, ensure:

- [ ] LoggerInterface injected in all handlers and repositories
- [ ] Command handlers log start, success, error with duration_ms
- [ ] Query handlers implement configurable logging (shouldLog pattern)
- [ ] Event handlers use PSR-3 (not log_message())
- [ ] Repository logs database errors and slow queries
- [ ] Value objects log validation failures before throwing
- [ ] Entities log business rule violations before throwing
- [ ] ErrorCodes class created with categorized constants
- [ ] Exceptions support getErrorCode()
- [ ] All log entries include domain and type context
- [ ] Correlation IDs auto-injected (verify in logs)
- [ ] Test script verifies all logging works
- [ ] PHPStan Level 8 passes
- [ ] Slevomat passes

---

**Next Steps:**
- Review Cookie domain implementation
- Apply patterns to your new domain
- Run test script to verify logging
- Monitor logs in production with MCP server

**Questions?** See `.claude/documentation/MCP_SERVER_CONFIG.md` for log access via AI.
