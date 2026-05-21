---
name: logging-architecture
description: Monolog-based logging architecture for this CQRS template — PSR-3 patterns in handlers, log channel naming, JSON format, MCP server access, correlation IDs, error codes, query-logging modes. Load when writing or changing log statements.
allowed-tools: [Read, Write, Edit, Glob, Grep, Bash]
---

# Logging Architecture

This template uses **Monolog** so logging survives a framework swap. The
`LoggerFactory` is PSR-3 compliant; `LoggingServiceProvider` is a thin
CodeIgniter adapter. AI assistants can read logs in real time via the
**Local Logs MCP Server**.

---

## File locations

```
writable/logs/
├── app-2025-10-22.json     # daily rotation, 30-day retention
├── app-2025-10-23.json
└── .gitkeep
```

All logs are **JSON** for AI parsing and structured analysis.

---

## Using logging in a CQRS handler

Inject `Psr\Log\LoggerInterface` in the constructor:

```php
use Psr\Log\LoggerInterface;

final readonly class CreateCookieHandler implements CommandHandlerInterface
{
    public function __construct(
        private CookieRepository $repository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {}

    public function handle(CommandInterface $command): int
    {
        assert($command instanceof CreateCookieCommand);

        $this->logger->info('Creating cookie', [
            'domain'    => 'Cookie',
            'command'   => 'CreateCookieCommand',
            'cookieName'=> $command->name,
            'price'     => $command->price,
        ]);

        try {
            $cookie   = Cookie::create(/* ... */);
            $cookieId = $this->repository->save($cookie);

            $this->logger->info('Cookie created successfully', [
                'domain'   => 'Cookie',
                'command'  => 'CreateCookieCommand',
                'cookieId' => $cookieId,
            ]);

            return $cookieId;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create cookie', [
                'domain'        => 'Cookie',
                'command'       => 'CreateCookieCommand',
                'exception'     => $e->getMessage(),
                'exceptionClass'=> $e::class,
            ]);

            throw $e;
        }
    }
}
```

---

## Channel naming

Dot notation, three slots:

- `{domain}.command.{action}` — commands (`cookie.command.create`)
- `{domain}.query.{action}` — queries (`cookie.query.getById`)
- `{domain}.event.{action}` — events (`cookie.event.created`)

```php
$logger = LoggerFactory::create('cookie.command.create');
```

The CQRS context processor uses the channel name to inject `domain` and
operation type into every log entry automatically.

---

## JSON log format

```json
{
  "message": "Creating cookie",
  "context": {
    "domain": "Cookie",
    "command": "CreateCookieCommand",
    "cookieName": "Chocolate Chip",
    "price": 9.99
  },
  "level": 200,
  "level_name": "INFO",
  "channel": "cookie.command.create",
  "datetime": "2025-10-22T10:30:00.000000+00:00",
  "extra": {
    "memory_usage": "2MB",
    "correlation_id": "3fd536da-71a6-4e85-8b62-769d4c86b99b"
  }
}
```

---

## Log levels (when to use which)

- **DEBUG** — detailed debug information.
- **INFO** — interesting events (command execution, success).
- **WARNING** — exceptional occurrences that are not errors.
- **ERROR** — runtime errors logged but not requiring immediate action.
- **CRITICAL** — database unavailable, security event, etc.

---

## Error codes

Standardized integer codes enable alerting, categorization, AI-based
analysis, and client-side error handling.

Registry per domain in `app/Domain/{Domain}/ErrorCodes.php`:

```php
// Validation errors (100-199)
public const int COOKIE_VALIDATION_NAME  = 101;
public const int COOKIE_VALIDATION_PRICE = 102;

// Not found errors (200-299)
public const int COOKIE_NOT_FOUND = 201;

// Business rule violations (300-399)
public const int COOKIE_BUSINESS_RULE_STOCK_NEGATIVE = 301;

// Repository errors (400-499)
public const int COOKIE_REPOSITORY_SAVE_FAILED = 401;
```

**Usage in exceptions:**

```php
throw ValidationException::required('name', ErrorCodes::COOKIE_VALIDATION_NAME);
throw DomainException::businessRuleViolation(
    'Stock negative',
    'details',
    ErrorCodes::COOKIE_BUSINESS_RULE_STOCK_NEGATIVE,
);

$errorCode = $exception->getErrorCode();
```

**Usage in log context:**

```php
$this->logger->error('Validation failed', [
    'domain'         => 'Cookie',
    'error_code'     => ErrorCodes::COOKIE_VALIDATION_NAME,
    'attempted_value'=> $value,
]);
```

---

## Correlation IDs

The `CorrelationIdProcessor` injects `correlation_id` into the `extra`
section of every log entry. Lifecycle:

1. HTTP request arrives → generate a UUID.
2. Command handler executes → logs include the same ID.
3. Event dispatched → still the same ID.
4. Event handler executes → still the same ID.
5. Repository queries → still the same ID.

You can also set / get / generate IDs manually:

```php
use App\Infrastructure\Logging\CorrelationIdService;

CorrelationIdService::generate();
CorrelationIdService::get();
CorrelationIdService::set($incomingCorrelationId);
```

---

## Query-logging modes

Configured in `app/Config/Logging.php`:

```php
public string $queryLoggingLevel  = 'errors'; // all|errors|slow|sampling
public int    $slowQueryThresholdMs = 100;
public float  $samplingRate         = 0.01;   // 1% for sampling mode
public bool   $correlationIdEnabled = true;
public bool   $businessMetricsEnabled = true;
```

| Mode | When to use |
|---|---|
| `all` | Development only |
| `errors` | Production default — log failed queries only |
| `slow` | Log only queries exceeding `slowQueryThresholdMs` |
| `sampling` | Log a random `samplingRate` of all queries |

A typical handler check:

```php
private function shouldLog(Config\Logging $config, float $duration, mixed $result): bool
{
    if ($duration > $config->slowQueryThresholdMs) {
        return true;
    }

    return match ($config->queryLoggingLevel) {
        'all'      => true,
        'errors'   => $result === null,
        'slow'     => false,
        'sampling' => (mt_rand() / mt_getrandmax()) < $config->samplingRate,
        default    => false,
    };
}
```

---

## Domain objects do NOT log directly

Value objects and entities throw exceptions; the command handler's `catch`
block logs them uniformly. This keeps domain code free of infrastructure
dependencies (and out of `deptrac` skip lists).

```php
// Value object:
throw ValidationException::required('name', ErrorCodes::COOKIE_VALIDATION_NAME);

// Entity:
throw DomainException::businessRuleViolation(
    'Stock cannot be negative',
    sprintf('Attempted to decrease stock by %d when only %d available', $quantity, $this->stock),
    ErrorCodes::COOKIE_BUSINESS_RULE_STOCK_NEGATIVE,
);
```

---

## AI access via MCP

The **Local Logs MCP Server** lets AI assistants query logs in real time:

> "Show me the last 50 lines from today's application log."
> "Find all ERROR level logs from the Cookie domain."
> "What happened with CreateCookieCommand in the last hour?"

Configuration: `.claude/documentation/MCP_SERVER_CONFIG.md`.

---

## Cookie domain = reference implementation

The Cookie domain has every logging feature wired in:

- Command handlers with duration tracking + error codes
- Query handlers with configurable logging mode
- Event handlers with PSR-3 logging
- Repository with DB-error, slow-query, and business-metrics logging
- Correlation IDs propagating end-to-end
- Error codes on every exception
- Comprehensive test coverage

Copy from there when you build a new domain.

---

## Testing logging

```bash
# Direct logger smoke test
php temp/test-logger-directly.php

# Verify JSON structure
head -1 writable/logs/app-$(date +%Y-%m-%d).json | python3 -m json.tool

# Tail in real time
tail -f writable/logs/app-$(date +%Y-%m-%d).json

# Full logging suite
php temp/test-comprehensive-logging.php
```

---

## Related references

- `.claude/documentation/LOGGING_BEST_PRACTICES.md` — full guide
- `.claude/documentation/MCP_SERVER_CONFIG.md` — MCP server setup
- Monolog: https://github.com/Seldaek/monolog
- PSR-3: https://www.php-fig.org/psr/psr-3/
