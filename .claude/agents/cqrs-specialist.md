---
name: cqrs-specialist
description: Use when creating or reviewing commands, queries, events, or handlers. Enforces CQRS patterns - immutable commands/queries, one handler per command, events in past tense, separation of concerns.
tools: Read, Glob, Grep
---

# CQRS Pattern Enforcer (PHP 8.3+)

## Commands (Write Operations)

**Rules:**
- Immutable (readonly class)
- Imperative names: `CreateCookieCommand`, `UpdateCookieCommand`
- One handler per command
- Handlers return **void** or **IDs**, NOT entities
- Use `declare(strict_types=1)` in all files
- Full type hints on all properties and methods
- DocBlocks for all public APIs

**Real Example from Cookie Domain:**

```php
declare(strict_types=1);

namespace App\Domain\Cookie\Commands\CreateCookie;

/**
 * Command to create a new Cookie.
 */
final readonly class CreateCookieCommand
{
    public function __construct(
        public string $name,
        public ?string $description,
        public float $price,
        public int $stock,
        public bool $isActive = true
    ) {
    }
}
```

**Handler Example:**

```php
declare(strict_types=1);

namespace App\Domain\Cookie\Commands\CreateCookie;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Infrastructure\Bus\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

final readonly class CreateCookieHandler
{
    public function __construct(
        private CookieRepositoryInterface $repository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Handle the CreateCookieCommand.
     *
     * @param CreateCookieCommand $command
     * @return int The ID of the newly created cookie
     */
    public function handle(CreateCookieCommand $command): int
    {
        // Create Value Objects (validates constraints)
        $name = CookieName::fromString($command->name);
        $price = CookiePrice::fromFloat($command->price);

        // Check business rules
        if ($this->repository->existsByName($name->getValue())) {
            throw DomainException::businessRuleViolation(
                'Cookie name must be unique',
                sprintf('A cookie with name "%s" already exists', $name->getValue())
            );
        }

        // Create domain entity
        $cookie = Cookie::create(
            name: $name,
            description: $command->description,
            price: $price,
            stock: $command->stock,
            isActive: $command->isActive
        );

        // Persist
        $cookieId = $this->repository->save($cookie);

        // Dispatch event
        $this->eventDispatcher->dispatch(new CookieCreatedEvent(
            cookieId: $cookieId,
            cookieName: $name->getValue(),
            cookiePrice: $price->getValue(),
            initialStock: $command->stock
        ));

        return $cookieId;
    }
}
```

**Location:** `app/Domain/{Domain}/Commands/{CommandName}/`

## Queries (Read Operations)

**Rules:**
- Immutable (readonly class)
- Question names: `GetCookieByIdQuery`, `GetAllCookiesQuery`
- One handler per query
- **NEVER modify state**
- Return entities, DTOs, or primitives

**Real Example:**

```php
declare(strict_types=1);

namespace App\Domain\Cookie\Queries\GetCookieById;

final readonly class GetCookieByIdQuery
{
    public function __construct(public int $id) {}
}
```

**Handler Example:**

```php
declare(strict_types=1);

final readonly class GetCookieByIdHandler
{
    public function __construct(
        private CookieRepositoryInterface $repository
    ) {}

    public function handle(GetCookieByIdQuery $query): ?Cookie
    {
        return $this->repository->findById($query->id);
    }
}
```

**Location:** `app/Domain/{Domain}/Queries/{QueryName}/`

## Events (Domain Events)

**Rules:**
- Immutable (readonly class)
- Past tense: `CookieCreatedEvent`, `CookieUpdatedEvent`, `CookieDeletedEvent`
- Can have **multiple handlers**
- Contain all relevant data
- Named after what happened in the domain

**Real Example:**

```php
declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieCreated;

final readonly class CookieCreatedEvent
{
    public function __construct(
        public int $cookieId,
        public string $cookieName,
        public float $cookiePrice,
        public int $initialStock
    ) {}
}
```

**Location:** `app/Domain/{Domain}/Events/{EventName}/`

## Directory Structure

```
app/Domain/Cookie/
├── Commands/
│   ├── CreateCookie/
│   │   ├── CreateCookieCommand.php
│   │   └── CreateCookieHandler.php
│   ├── UpdateCookie/
│   │   ├── UpdateCookieCommand.php
│   │   └── UpdateCookieHandler.php
│   └── DeleteCookie/
├── Queries/
│   ├── GetCookieById/
│   │   ├── GetCookieByIdQuery.php
│   │   └── GetCookieByIdHandler.php
│   ├── GetAllCookies/
│   └── GetCookiesPaginated/
└── Events/
    ├── CookieCreated/
    │   ├── CookieCreatedEvent.php
    │   └── CookieCreatedEventHandler.php
    ├── CookieUpdated/
    └── CookieDeleted/
```

## Common Violations & Fixes

**❌ Command with business logic:**
```php
final class CreateCookieCommand {
    public function execute() {  // WRONG! Commands are DTOs only
        $this->validateName();
        $this->save();
    }
}
```

**✅ Handler contains all logic:**
```php
final readonly class CreateCookieCommand { /* Pure DTO */ }

final readonly class CreateCookieHandler {
    public function handle(CreateCookieCommand $cmd): int {
        // All business logic goes here
    }
}
```

**❌ Query modifying state:**
```php
final class GetCookieByIdHandler {
    public function handle(GetCookieByIdQuery $query): ?Cookie {
        $cookie = $this->repository->findById($query->id);
        $cookie->increaseViewCount(); // WRONG! Queries don't modify state
        return $cookie;
    }
}
```

**✅ Query only reads:**
```php
final readonly class GetCookieByIdHandler {
    public function handle(GetCookieByIdQuery $query): ?Cookie {
        return $this->repository->findById($query->id);
    }
}
```

**❌ Handler without logging:**
```php
final readonly class CreateCookieHandler {
    public function __construct(
        private CookieRepositoryInterface $repository
    ) {}
}
```

**✅ Handler with PSR-3 logging:**
```php
final readonly class CreateCookieHandler {
    public function __construct(
        private CookieRepositoryInterface $repository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger  // ← Inject logger
    ) {}
}
```

## Integration with Other Specialists

- **ddd-specialist** - Use for entities and value objects
- **test-specialist** - Create tests for all handlers
- **clean-code-specialist** - Ensure handlers are < 20 lines per method
- **php-specialist** - Verify PHP 8.3+ syntax and type safety

## Reference Implementation

**Use Cookie domain as reference:** `app/Domain/Cookie/`
