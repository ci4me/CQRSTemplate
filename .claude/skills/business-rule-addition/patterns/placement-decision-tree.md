# Business Rule Placement Decision Tree

This flowchart helps determine the correct location for implementing business rules in a CQRS/DDD architecture.

---

## Decision Flowchart

```
START: Need to add a business rule
│
├─ Q1: Does the rule validate a SINGLE VALUE's format, range, or validity?
│  │
│  ├─ YES → Place in VALUE OBJECT
│  │         Examples:
│  │         - Price must be >= $0.01
│  │         - Name length 3-100 characters
│  │         - Email must match format
│  │         - Date must be in future/past
│  │
│  └─ NO → Continue to Q2
│
├─ Q2: Does the rule check a SINGLE ENTITY's invariant or internal consistency?
│  │
│  ├─ YES → Place in ENTITY METHOD
│  │         Examples:
│  │         - Cannot decrease stock below zero
│  │         - Cannot activate product without stock
│  │         - Cannot apply discount to already-discounted item
│  │         - Cannot change status from X to Y
│  │
│  └─ NO → Continue to Q3
│
└─ Q3: Does the rule involve MULTIPLE ENTITIES, aggregates, or external data?
   │
   └─ YES → Place in HANDLER (Domain Service)
             Examples:
             - Cannot create duplicate names (checks database)
             - Cannot exceed system limits (checks other aggregates)
             - User must have permission (checks external service)
             - Cannot violate referential integrity (checks related entities)
```

---

## Detailed Examples from Cookie Domain

### Example 1: Value Object Rule

**Rule:** Cookie name must be between 3 and 100 characters.

**Placement:** `app/Domain/Cookie/ValueObjects/CookieName.php`

**Why:** This rule validates a single value's format and range. It doesn't depend on any other data.

```php
final readonly class CookieName
{
    private function __construct(private string $value)
    {
        if (mb_strlen($value) < 3 || mb_strlen($value) > 100) {
            throw ValidationException::invalidLength('Cookie name', 3, 100);
        }
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }
}
```

**Test:** Unit test with boundary values (2, 3, 100, 101 characters).

---

### Example 2: Value Object Rule (Another)

**Rule:** Cookie price must be at least $0.01.

**Placement:** `app/Domain/Cookie/ValueObjects/CookiePrice.php`

**Why:** Validates single value's business constraint (minimum price).

```php
final readonly class CookiePrice
{
    private function __construct(private float $value)
    {
        if ($value < 0.01) {
            throw ValidationException::belowMinimum('Price', 0.01);
        }
    }

    public static function fromFloat(float $value): self
    {
        return new self($value);
    }
}
```

**Test:** Unit test with values (0, 0.001, 0.01, 1.00).

---

### Example 3: Entity Method Rule

**Rule:** Cannot mark a cookie as "inactive" if it has pending orders.

**Placement:** `app/Domain/Cookie/Entities/Cookie.php` (hypothetical method)

**Why:** This rule checks the entity's internal state consistency.

```php
final class Cookie
{
    private function __construct(
        private CookieId $id,
        private CookieName $name,
        private CookiePrice $price,
        private CookieStatus $status,
        private int $pendingOrders
    ) {}

    public function markAsInactive(): void
    {
        if ($this->pendingOrders > 0) {
            throw ValidationException::ruleViolated(
                'Cannot mark cookie as inactive while it has pending orders'
            );
        }

        $this->status = CookieStatus::INACTIVE;
    }
}
```

**Test:** Unit test with mock entity (pendingOrders = 0 succeeds, > 0 throws).

---

### Example 4: Entity Method Rule (Another)

**Rule:** Cannot reduce price by more than 50% at once.

**Placement:** `app/Domain/Cookie/Entities/Cookie.php`

**Why:** Compares new value against current entity state.

```php
public function updatePrice(CookiePrice $newPrice): void
{
    $currentValue = $this->price->getValue();
    $newValue = $newPrice->getValue();
    $reduction = ($currentValue - $newValue) / $currentValue;

    if ($reduction > 0.5) {
        throw ValidationException::ruleViolated(
            'Cannot reduce price by more than 50% at once'
        );
    }

    $this->price = $newPrice;
}
```

**Test:** Unit test with various reductions (40%, 50%, 51%, 100%).

---

### Example 5: Handler Rule

**Rule:** Cannot create a cookie with a duplicate name.

**Placement:** `app/Domain/Cookie/Commands/CreateCookie/CreateCookieHandler.php`

**Why:** Requires querying database (external aggregate) to check for duplicates.

```php
final readonly class CreateCookieHandler
{
    public function __construct(
        private CookieRepository $repository
    ) {}

    public function handle(CreateCookieCommand $command): CookieId
    {
        $name = CookieName::fromString($command->name);

        // Cross-aggregate check (queries database)
        if ($this->repository->existsByName($name)) {
            throw ValidationException::ruleViolated(
                'Cannot create cookie: name already exists'
            );
        }

        $cookie = Cookie::create($name, $command->price);
        $this->repository->save($cookie);

        return $cookie->getId();
    }
}
```

**Test:** Integration test with database (create once succeeds, create twice throws).

---

### Example 6: Handler Rule (Another)

**Rule:** Cannot create more than 1000 cookies (system limit).

**Placement:** `app/Domain/Cookie/Commands/CreateCookie/CreateCookieHandler.php`

**Why:** Requires checking aggregate count across entire system.

```php
public function handle(CreateCookieCommand $command): CookieId
{
    // System-wide constraint check
    $totalCount = $this->repository->count();
    if ($totalCount >= 1000) {
        throw ValidationException::systemLimitReached('Cookies', 1000);
    }

    $cookie = Cookie::create(/* ... */);
    $this->repository->save($cookie);

    return $cookie->getId();
}
```

**Test:** Integration test with database (count = 999 succeeds, count = 1000 throws).

---

## Quick Reference Table

| Rule Type | Location | Depends On | Test Type | Example |
|-----------|----------|------------|-----------|---------|
| Single value format/range | Value Object | Nothing | Unit | Email format, price >= $0.01 |
| Single entity invariant | Entity Method | Entity state only | Unit (with mocks) | Stock cannot go negative |
| Multiple entities/aggregates | Handler | Database/external data | Integration | No duplicate names |
| System-wide constraint | Handler | Aggregate queries | Integration | Max 1000 records |
| External dependency check | Handler | External service | Integration | User has permission |

---

## Common Mistakes to Avoid

❌ **DON'T** put database queries in Value Objects or Entities
✅ **DO** keep Value Objects and Entities pure (no I/O)

❌ **DON'T** put validation logic in Handlers if it only depends on the value itself
✅ **DO** put format/range validation in Value Objects

❌ **DON'T** create anemic entities by putting all business logic in Handlers
✅ **DO** put entity-level invariants in Entity methods

---

## When in Doubt

1. **Can it be validated without any other data?** → Value Object
2. **Does it only need the entity's own properties?** → Entity Method
3. **Does it need to query or check external data?** → Handler

---

**Use this decision tree every time you add a business rule to ensure correct placement and maintainability.**
