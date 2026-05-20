---
name: ddd-specialist
description: Use when designing or reviewing entities or value objects. Enforces DDD patterns - immutable value objects, factory methods (create/reconstitute), protected invariants, ubiquitous language.
tools: Read, Edit
---

# DDD Pattern Enforcer (PHP 8.3+)

## Value Objects

**Rules:**
- Readonly properties (immutable)
- Self-validating (throw in constructor)
- Named factories: `fromString()`, `fromFloat()`, `fromInt()`
- No identity, equality by value
- No setters, create new instance instead
- Use `declare(strict_types=1)`
- Full type hints
- DocBlocks for all public APIs

**Real Example from Cookie Domain:**

```php
declare(strict_types=1);

namespace App\Domain\Cookie\ValueObjects;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Shared\Exceptions\ValidationException;

/**
 * Value Object representing a Cookie name.
 *
 * Business Rules:
 * - Name must be between 3 and 100 characters
 * - Name is trimmed of whitespace
 * - Name cannot be empty after trimming
 */
final readonly class CookieName
{
    private const MIN_LENGTH = 3;
    private const MAX_LENGTH = 100;

    private string $value;

    private function __construct(string $name)
    {
        $normalized = trim($name);

        if ($normalized === '') {
            throw ValidationException::required('name', ErrorCodes::COOKIE_VALIDATION_NAME);
        }

        $length = mb_strlen($normalized);

        if ($length < self::MIN_LENGTH) {
            throw ValidationException::fieldTooShort('name', self::MIN_LENGTH, $length, ErrorCodes::COOKIE_VALIDATION_NAME);
        }

        if ($length > self::MAX_LENGTH) {
            throw ValidationException::fieldTooLong('name', self::MAX_LENGTH, $length, ErrorCodes::COOKIE_VALIDATION_NAME);
        }

        $this->value = $normalized;
    }

    public static function fromString(string $name): self
    {
        return new self($name);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function equals(CookieName $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

**Location:** `app/Domain/{Domain}/ValueObjects/`

## Entities

**Rules:**
- Have unique identity (ID)
- Private constructor
- Factory methods: `create()` and `reconstitute()`
- Protect invariants (business rules)
- Command methods, not setters
- Use value objects for properties

**Real Example from Cookie Domain:**

```php
declare(strict_types=1);

namespace App\Domain\Cookie\Entities;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Exceptions\DomainException;

/**
 * Cookie Domain Entity (Aggregate Root).
 *
 * Business Rules:
 * - Cookie name must be unique (enforced by repository)
 * - Price must be greater than zero
 * - Stock cannot be negative
 */
final class Cookie
{
    private ?int $id = null;
    private CookieName $name;
    private ?string $description;
    private CookiePrice $price;
    private int $stock;
    private bool $isActive;

    private function __construct(
        CookieName $name,
        ?string $description,
        CookiePrice $price,
        int $stock,
        bool $isActive = true
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->price = $price;
        $this->setStock($stock);
        $this->isActive = $isActive;
    }

    /**
     * Create a new Cookie (factory method for new cookies).
     */
    public static function create(
        CookieName $name,
        ?string $description,
        CookiePrice $price,
        int $stock,
        bool $isActive = true
    ): self {
        return new self($name, $description, $price, $stock, $isActive);
    }

    /**
     * Reconstitute a Cookie from persistence.
     */
    public static function reconstitute(
        int $id,
        CookieName $name,
        ?string $description,
        CookiePrice $price,
        int $stock,
        bool $isActive,
        ?string $createdAt,
        ?string $updatedAt,
        ?string $deletedAt = null
    ): self {
        $cookie = new self($name, $description, $price, $stock, $isActive);
        $cookie->id = $id;
        $cookie->createdAt = $createdAt;
        $cookie->updatedAt = $updatedAt;
        $cookie->deletedAt = $deletedAt;

        return $cookie;
    }

    /**
     * Decrease stock by a given quantity.
     *
     * Business Rule: Stock cannot go negative.
     */
    public function decreaseStock(int $quantity): void
    {
        $newStock = $this->stock - $quantity;

        if ($newStock < 0) {
            throw DomainException::businessRuleViolation(
                'Stock cannot be negative',
                sprintf('Attempted to decrease stock by %d when only %d available', $quantity, $this->stock),
                ErrorCodes::COOKIE_BUSINESS_RULE_STOCK_NEGATIVE
            );
        }

        $this->stock = $newStock;
    }

    private function setStock(int $stock): void
    {
        if ($stock < 0) {
            throw ValidationException::tooSmall('stock', 0, $stock, ErrorCodes::COOKIE_VALIDATION_STOCK);
        }

        $this->stock = $stock;
    }

    public function isAvailable(): bool
    {
        return $this->isActive && $this->deletedAt === null && $this->stock > 0;
    }

    public function isOutOfStock(): bool
    {
        return $this->stock === 0;
    }

    // Getters...
    public function getId(): ?int { return $this->id; }
    public function getName(): CookieName { return $this->name; }
    public function getPrice(): CookiePrice { return $this->price; }
    public function getStock(): int { return $this->stock; }
}
```

**Location:** `app/Domain/{Domain}/Entities/`

## Ubiquitous Language

Use business terminology in code:
- `decreaseStock(int $quantity)` not `setStock(int $stock)`
- `applyDiscount(Percentage $discount)` not `updatePrice(float $newPrice)`
- `isOutOfStock(): bool` not `getStock() === 0`
- `activate()` not `setActive(true)`
- `deactivate()` not `setActive(false)`

## Common Violations & Fixes

**❌ Anemic Domain Model (no business logic):**
```php
class Cookie {
    private float $price;

    public function setPrice(float $price): void {
        $this->price = $price;  // No validation!
    }
}
```

**✅ Rich Domain Model (with business rules):**
```php
final class Cookie {
    private CookiePrice $price;

    public function updatePrice(CookiePrice $newPrice): void {
        // Validation enforced by CookiePrice value object
        $this->price = $newPrice;
    }
}
```

**❌ Value Object with setters:**
```php
final class CookieName {
    private string $value;

    public function setValue(string $value): void {  // WRONG!
        $this->value = $value;
    }
}
```

**✅ Immutable Value Object:**
```php
final readonly class CookieName {
    private string $value;

    // No setters! Create new instance instead
    public function withNewValue(string $newValue): self {
        return new self($newValue);
    }
}
```

**❌ Public constructor:**
```php
final class Cookie {
    public function __construct(
        private CookieName $name,
        private CookiePrice $price
    ) {}
}
```

**✅ Factory methods:**
```php
final class Cookie {
    private function __construct(/* ... */) {}

    public static function create(/* ... */): self { /* ... */ }
    public static function reconstitute(/* ... */): self { /* ... */ }
}
```

**❌ No error codes:**
```php
throw ValidationException::required('name');  // WRONG!
```

**✅ With error codes:**
```php
throw ValidationException::required('name', ErrorCodes::COOKIE_VALIDATION_NAME);
```

## Integration with Other Specialists

- **cqrs-specialist** - Use for commands/queries that use these entities
- **test-specialist** - Create unit tests for all entity methods
- **clean-code-specialist** - Ensure methods are < 20 lines
- **php-specialist** - Verify PHP 8.3+ syntax and type safety

## Reference Implementation

**Use Cookie domain as reference:** `app/Domain/Cookie/`

**Value Objects:** `app/Domain/Cookie/ValueObjects/CookieName.php`, `CookiePrice.php`
**Entities:** `app/Domain/Cookie/Entities/Cookie.php`
