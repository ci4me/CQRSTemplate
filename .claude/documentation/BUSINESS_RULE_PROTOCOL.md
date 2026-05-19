# Business Rule Protocol

**Guidelines for implementing business rules in CQRS/DDD architecture.**

---

## Rule Placement Decision Tree

```
Is the rule about a single value's validity?
    YES → Value Object
    NO  → Continue

Is the rule about a single entity's consistency?
    YES → Entity Method
    NO  → Continue

Is the rule about multiple entities or cross-aggregate?
    YES → Domain Service (Handler)
    NO  → Re-evaluate the rule
```

---

## Type 1: Value Object Rules

**When:** Rule validates a single value (min/max, format, range)

**Example:** "Cookie price must be at least $0.01"

**Implementation:**
```php
final readonly class CookiePrice
{
    private const MIN_PRICE = 0.01;

    private function __construct(private float $value)
    {
        if ($value < self::MIN_PRICE) {
            throw ValidationException::tooSmall('price', self::MIN_PRICE, $value);
        }
    }
}
```

**Test:**
```php
public function test_throws_exception_for_price_below_minimum(): void
{
    $this->expectException(ValidationException::class);
    CookiePrice::fromFloat(0.00);
}
```

---

## Type 2: Entity Rules

**When:** Rule involves multiple properties of same entity

**Example:** "Cannot decrease stock below zero"

**Implementation:**
```php
final class Cookie
{
    public function decreaseStock(int $quantity): void
    {
        if ($quantity <= 0) {
            throw ValidationException::tooSmall('quantity', 1, $quantity);
        }

        $newStock = $this->stock - $quantity;
        if ($newStock < 0) {
            throw ValidationException::insufficientStock(
                $this->name->getValue(),
                $quantity,
                $this->stock
            );
        }

        $this->stock = $newStock;
    }
}
```

**Test:**
```php
public function test_cannot_decrease_stock_below_zero(): void
{
    $cookie = Cookie::create(/* ... */, stock: 5);

    $this->expectException(ValidationException::class);
    $cookie->decreaseStock(10);
}
```

---

## Type 3: Handler Rules (Domain Services)

**When:** Rule involves multiple entities or external dependencies

**Example:** "Cannot create cookie with duplicate name"

**Implementation:**
```php
final class CreateCookieHandler
{
    public function handle(CreateCookieCommand $command): int
    {
        // Business Rule: Check for duplicate name
        $existing = $this->repository->findByName($command->name);
        if ($existing !== null) {
            throw ValidationException::duplicateName('cookie', $command->name);
        }

        $cookie = Cookie::create(/* ... */);
        return $this->repository->save($cookie);
    }
}
```

**Test:**
```php
public function test_throws_exception_for_duplicate_name(): void
{
    $repository = $this->createMock(CookieRepository::class);
    $repository->method('findByName')->willReturn($this->createMock(Cookie::class));

    $handler = new CreateCookieHandler($repository, /* ... */);

    $this->expectException(ValidationException::class);
    $handler->handle(new CreateCookieCommand(name: 'Chocolate Chip', /* ... */));
}
```

---

## Type 4: Invariant Rules

**When:** Rule must ALWAYS be true for entity

**Example:** "Active cookies must have stock > 0"

**Implementation:**
```php
final class Cookie
{
    public function activate(): void
    {
        // Invariant: Cannot activate cookie with zero stock
        if ($this->stock === 0) {
            throw ValidationException::cannotActivateWithoutStock($this->name->getValue());
        }

        $this->isActive = true;
    }
}
```

---

## Rule Documentation Template

For each business rule, document:

```php
/**
 * {Action description}.
 *
 * @ai-context Business Rule: {Rule description}
 *             Enforces the constraint that {constraint explanation}.
 *             This rule exists because {business justification}.
 *
 * @ai-pattern {Pattern name} - {Pattern description}
 *
 * @ai-example
 * ```php
 * ${entity}->{action}({params});
 * // Throws ValidationException if {violation condition}
 * ```
 *
 * @param {Type} ${param} {Parameter description}
 *
 * @return {Type} {Return description}
 *
 * @throws ValidationException If {violation description}
 *
 * @debugPoint Set breakpoint to inspect {what to inspect}
 */
```

---

## Complex Rules Example

**Rule:** "Cannot apply discount if cookie is already discounted or if discount exceeds price"

```php
final class Cookie
{
    /**
     * Applies a discount to the cookie price.
     *
     * @ai-context Business Rule: Discount Validation
     *             - Cannot apply discount if already discounted
     *             - Discount cannot exceed original price
     *             - Must maintain minimum price of $0.01
     *
     * @param CookiePrice $discountAmount The discount to apply
     *
     * @return void
     *
     * @throws ValidationException If cookie already discounted
     * @throws ValidationException If discount exceeds price
     * @throws ValidationException If final price below minimum
     */
    public function applyDiscount(CookiePrice $discountAmount): void
    {
        // Rule 1: Cannot discount already discounted cookie
        if ($this->isDiscounted) {
            throw ValidationException::alreadyDiscounted($this->name->getValue());
        }

        // Rule 2: Discount cannot exceed price
        if ($discountAmount->isGreaterThan($this->price)) {
            throw ValidationException::discountExceedsPrice(
                $discountAmount->getValue(),
                $this->price->getValue()
            );
        }

        // Rule 3: Final price must meet minimum
        $newPrice = $this->price->subtract($discountAmount);
        // CookiePrice validation ensures minimum in constructor

        $this->price = $newPrice;
        $this->isDiscounted = true;
    }
}
```

---

## Testing Business Rules Checklist

- [ ] Test happy path (rule passes)
- [ ] Test each violation condition separately
- [ ] Test boundary values (min, max, zero, negative)
- [ ] Test edge cases (null, empty, extreme values)
- [ ] Document WHY rule exists in test comments

---

## Common Mistakes to Avoid

**❌ Don't:** Put validation in controllers
**✅ Do:** Use value objects or handlers

**❌ Don't:** Use database queries in entities
**✅ Do:** Pass required data to entity methods

**❌ Don't:** Throw generic exceptions
**✅ Do:** Use specific ValidationException methods

**❌ Don't:** Skip tests for business rules
**✅ Do:** Test each rule violation condition

---

## Specialists to Use

- **Value Object Rules:** `ddd-specialist`, `php-specialist`
- **Entity Rules:** `ddd-specialist`, `clean-code-specialist`
- **Handler Rules:** `cqrs-specialist`, `clean-code-specialist`
- **All Rules:** `test-specialist` (for comprehensive testing)

---

**Business rules are the CORE of your domain. Implement them correctly and test thoroughly.**
