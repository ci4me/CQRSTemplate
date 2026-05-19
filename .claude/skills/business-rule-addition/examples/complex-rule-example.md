# Complex Business Rule Example: Entity Method with Full Test Suite

This example demonstrates implementing a complex business rule at the entity level with comprehensive testing.

---

## Business Rule: Cannot Reduce Price by More Than 50% at Once

**Context:** In the Cookie domain, we want to prevent drastic price changes that could indicate errors or abuse.

**Rule:** When updating a cookie's price, the new price cannot be more than 50% lower than the current price.

**Placement:** Entity Method (in `Cookie` entity)

**Why Entity Method?**
- The rule compares new value against current entity state
- No external data needed (just entity's current price)
- Protects entity invariant (reasonable price changes)

---

## Implementation

### File: `app/Domain/Cookie/Entities/Cookie.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Entities;

use App\Domain\Cookie\ValueObjects\CookieId;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Cookie\ValueObjects\CookieDescription;
use App\Domain\Shared\Exceptions\ValidationException;

final class Cookie
{
    private function __construct(
        private CookieId $id,
        private CookieName $name,
        private CookiePrice $price,
        private CookieDescription $description,
        private \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
        private ?\DateTimeImmutable $deletedAt = null
    ) {}

    /**
     * Factory method for creating new cookies.
     */
    public static function create(
        CookieName $name,
        CookiePrice $price,
        CookieDescription $description
    ): self {
        return new self(
            id: CookieId::generate(),
            name: $name,
            price: $price,
            description: $description,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable()
        );
    }

    /**
     * Factory method for reconstituting from database.
     */
    public static function reconstitute(
        CookieId $id,
        CookieName $name,
        CookiePrice $price,
        CookieDescription $description,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $deletedAt
    ): self {
        return new self(
            id: $id,
            name: $name,
            price: $price,
            description: $description,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            deletedAt: $deletedAt
        );
    }

    /**
     * Update the cookie's price with business rule enforcement.
     *
     * @ai-context Business Rule: Cannot Reduce Price by More Than 50%
     *             Enforces: Prevents drastic price drops that could indicate errors
     *             Reason: Protects business from accidental or malicious price manipulation
     *
     * @ai-pattern Entity Method Business Rule
     *
     * @ai-example
     * ```php
     * $cookie->updatePrice(CookiePrice::fromFloat(5.00)); // Current: $10, New: $5 (50% reduction) → OK
     * $cookie->updatePrice(CookiePrice::fromFloat(4.99)); // Current: $10, New: $4.99 (50.1% reduction) → THROWS
     * ```
     *
     * @param CookiePrice $newPrice The new price to set
     *
     * @throws ValidationException If price reduction exceeds 50%
     *
     * @debugPoint Inspect $reduction value and current/new prices during debugging
     */
    public function updatePrice(CookiePrice $newPrice): void
    {
        $currentValue = $this->price->getValue();
        $newValue = $newPrice->getValue();

        // Calculate reduction percentage
        if ($newValue < $currentValue) {
            $reduction = ($currentValue - $newValue) / $currentValue;

            if ($reduction > 0.5) {
                throw ValidationException::ruleViolated(
                    sprintf(
                        'Cannot reduce price by more than 50%%. Current: $%.2f, New: $%.2f (%.1f%% reduction)',
                        $currentValue,
                        $newValue,
                        $reduction * 100
                    )
                );
            }
        }

        // Price increase has no limit
        $this->price = $newPrice;
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Getters
    public function getId(): CookieId
    {
        return $this->id;
    }

    public function getName(): CookieName
    {
        return $this->name;
    }

    public function getPrice(): CookiePrice
    {
        return $this->price;
    }

    public function getDescription(): CookieDescription
    {
        return $this->description;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
```

---

## Complete Test Suite

### File: `tests/Unit/Domain/Cookie/Entities/CookieTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Entities;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ValueObjects\CookieId;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Cookie\ValueObjects\CookieDescription;
use App\Domain\Shared\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class CookieTest extends TestCase
{
    // ... other tests ...

    /**
     * Test 1: Happy Path - Price Increase
     */
    public function test_allows_price_increase_without_limit(): void
    {
        // Arrange
        $cookie = Cookie::create(
            CookieName::fromString('Chocolate Chip'),
            CookiePrice::fromFloat(10.00),
            CookieDescription::fromString('Delicious cookie')
        );

        // Act - increase price by 100%
        $cookie->updatePrice(CookiePrice::fromFloat(20.00));

        // Assert
        $this->assertSame(20.00, $cookie->getPrice()->getValue());
    }

    /**
     * Test 2: Happy Path - Exactly 50% Reduction (Boundary)
     */
    public function test_allows_exactly_50_percent_price_reduction(): void
    {
        // Arrange
        $cookie = Cookie::create(
            CookieName::fromString('Chocolate Chip'),
            CookiePrice::fromFloat(10.00),
            CookieDescription::fromString('Delicious cookie')
        );

        // Act - reduce price by exactly 50%
        $cookie->updatePrice(CookiePrice::fromFloat(5.00));

        // Assert
        $this->assertSame(5.00, $cookie->getPrice()->getValue());
    }

    /**
     * Test 3: Violation - Just Over 50% Reduction (Boundary)
     */
    public function test_throws_exception_when_price_reduction_just_exceeds_50_percent(): void
    {
        // Arrange
        $cookie = Cookie::create(
            CookieName::fromString('Chocolate Chip'),
            CookiePrice::fromFloat(10.00),
            CookieDescription::fromString('Delicious cookie')
        );

        // Assert
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot reduce price by more than 50%');

        // Act - reduce price by 50.1%
        $cookie->updatePrice(CookiePrice::fromFloat(4.99));
    }

    /**
     * Test 4: Violation - Extreme Price Reduction
     */
    public function test_throws_exception_when_price_reduction_is_extreme(): void
    {
        // Arrange
        $cookie = Cookie::create(
            CookieName::fromString('Chocolate Chip'),
            CookiePrice::fromFloat(100.00),
            CookieDescription::fromString('Delicious cookie')
        );

        // Assert
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot reduce price by more than 50%');

        // Act - reduce price by 99%
        $cookie->updatePrice(CookiePrice::fromFloat(1.00));
    }

    /**
     * Test 5: Edge Case - Small Price Reduction
     */
    public function test_allows_small_price_reduction(): void
    {
        // Arrange
        $cookie = Cookie::create(
            CookieName::fromString('Chocolate Chip'),
            CookiePrice::fromFloat(10.00),
            CookieDescription::fromString('Delicious cookie')
        );

        // Act - reduce price by 10%
        $cookie->updatePrice(CookiePrice::fromFloat(9.00));

        // Assert
        $this->assertSame(9.00, $cookie->getPrice()->getValue());
    }

    /**
     * Test 6: Edge Case - No Price Change
     */
    public function test_allows_updating_price_to_same_value(): void
    {
        // Arrange
        $cookie = Cookie::create(
            CookieName::fromString('Chocolate Chip'),
            CookiePrice::fromFloat(10.00),
            CookieDescription::fromString('Delicious cookie')
        );

        // Act - set to same price
        $cookie->updatePrice(CookiePrice::fromFloat(10.00));

        // Assert
        $this->assertSame(10.00, $cookie->getPrice()->getValue());
    }

    /**
     * Test 7: Boundary - 49.9% Reduction (Should Pass)
     */
    public function test_allows_49_point_9_percent_reduction(): void
    {
        // Arrange
        $cookie = Cookie::create(
            CookieName::fromString('Chocolate Chip'),
            CookiePrice::fromFloat(10.00),
            CookieDescription::fromString('Delicious cookie')
        );

        // Act - reduce by 49.9%
        $cookie->updatePrice(CookiePrice::fromFloat(5.01));

        // Assert
        $this->assertSame(5.01, $cookie->getPrice()->getValue());
    }

    /**
     * Test 8: State Verification - UpdatedAt Timestamp
     */
    public function test_updates_timestamp_when_price_changes(): void
    {
        // Arrange
        $cookie = Cookie::create(
            CookieName::fromString('Chocolate Chip'),
            CookiePrice::fromFloat(10.00),
            CookieDescription::fromString('Delicious cookie')
        );
        
        $originalUpdatedAt = $cookie->getUpdatedAt();
        sleep(1); // Ensure timestamp difference

        // Act
        $cookie->updatePrice(CookiePrice::fromFloat(9.00));

        // Assert
        $this->assertGreaterThan(
            $originalUpdatedAt->getTimestamp(),
            $cookie->getUpdatedAt()->getTimestamp()
        );
    }
}
```

---

## Test Coverage Analysis

### Coverage Breakdown:
- ✅ Happy path: price increase (Test 1)
- ✅ Happy path: exactly 50% reduction (Test 2)
- ✅ Boundary: just over 50% reduction (Test 3)
- ✅ Violation: extreme reduction (Test 4)
- ✅ Edge case: small reduction (Test 5)
- ✅ Edge case: no change (Test 6)
- ✅ Boundary: 49.9% reduction passes (Test 7)
- ✅ State verification: timestamp updated (Test 8)

### What Makes This Complete:
1. **Happy paths tested** - Normal valid operations
2. **Boundaries tested** - Exactly at the threshold (50%)
3. **Violations tested** - Just over threshold and extreme cases
4. **Edge cases covered** - Small changes, no changes
5. **State verification** - Side effects checked (timestamp)
6. **Clear naming** - Test names describe exact scenario
7. **Arrange-Act-Assert** - Consistent test structure

---

## Key Takeaways

1. **Entity methods protect invariants** - The entity enforces its own business rules
2. **Comprehensive testing is critical** - 8 tests for one business rule ensures confidence
3. **Boundary testing catches bugs** - Testing at exactly 50% and just over catches edge cases
4. **Descriptive exception messages** - Include current, new, and percentage for debugging
5. **AI-optimized docblocks** - Help future AI assistants understand the rule's context

---

## Running the Tests

```bash
# Run just this test file
vendor/bin/phpunit tests/Unit/Domain/Cookie/Entities/CookieTest.php

# Run with verbose output
vendor/bin/phpunit --testdox tests/Unit/Domain/Cookie/Entities/CookieTest.php

# Expected output:
# ✔ Allows price increase without limit
# ✔ Allows exactly 50 percent price reduction
# ✔ Throws exception when price reduction just exceeds 50 percent
# ✔ Throws exception when price reduction is extreme
# ✔ Allows small price reduction
# ✔ Allows updating price to same value
# ✔ Allows 49 point 9 percent reduction
# ✔ Updates timestamp when price changes
```

---

**Use this example as a template when implementing complex business rules in entity methods.**
