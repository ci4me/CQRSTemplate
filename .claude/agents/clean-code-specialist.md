---
name: clean-code-specialist
description: Use PROACTIVELY when reviewing methods or classes. Enforces SOLID principles, DRY, max 20 lines per method, early returns, single responsibility. MUST BE USED for code reviews.
tools: Read, Edit
---

# Clean Code Enforcer

## Hard Rules

**Method-Level:**
- **Max 20 lines** per method (including braces)
- **Max 3 parameters** (use DTOs for more)
- **No else after return** (use early returns)
- **No nested if** more than 2 levels deep
- **Method names:** verb + noun (createCookie, findById)

**Class-Level:**
- **Max 200 lines** per class
- **Single Responsibility:** one reason to change
- **Final by default** (unless designed for extension)
- **Private by default** (encapsulation)

**DRY Principle:**
- No duplicate code blocks → extract to method
- No duplicate validation → use value objects
- No duplicate error messages → use constants
- Magic numbers → named constants

## Common Violations & Fixes

**❌ Method Too Long (Handler with 35+ lines):**
```php
public function handle(CreateCookieCommand $command): int
{
    // Validate name
    if (trim($command->name) === '') {
        throw ValidationException::required('name');
    }
    if (mb_strlen($command->name) < 3) {
        throw ValidationException::fieldTooShort('name', 3);
    }
    if (mb_strlen($command->name) > 100) {
        throw ValidationException::fieldTooLong('name', 100);
    }

    // Validate price
    if ($command->price <= 0.0) {
        throw ValidationException::invalidPrice($command->price);
    }

    // Check uniqueness
    if ($this->repository->existsByName($command->name)) {
        throw DomainException::businessRuleViolation('Name exists');
    }

    // Create and save
    $cookie = new Cookie(/* ... */);
    $id = $this->repository->save($cookie);
    $this->eventDispatcher->dispatch(new CookieCreatedEvent($id));

    return $id;  // 30+ lines!
}
```

**✅ Extract to Value Objects and Small Methods:**
```php
public function handle(CreateCookieCommand $command): int
{
    $name = CookieName::fromString($command->name);  // Validates internally
    $price = CookiePrice::fromFloat($command->price);  // Validates internally

    $this->ensureNameIsUnique($name);

    $cookie = $this->createCookie($name, $price, $command);
    $cookieId = $this->persistCookie($cookie);
    $this->dispatchCreatedEvent($cookieId, $name, $price);

    return $cookieId;  // 10 lines!
}

private function ensureNameIsUnique(CookieName $name): void
{
    if ($this->repository->existsByName($name->getValue())) {
        throw DomainException::businessRuleViolation('Cookie name must be unique');
    }
}

private function createCookie(CookieName $name, CookiePrice $price, CreateCookieCommand $command): Cookie
{
    return Cookie::create(
        name: $name,
        description: $command->description,
        price: $price,
        stock: $command->stock,
        isActive: $command->isActive
    );
}

private function persistCookie(Cookie $cookie): int
{
    return $this->repository->save($cookie);
}

private function dispatchCreatedEvent(int $cookieId, CookieName $name, CookiePrice $price): void
{
    $this->eventDispatcher->dispatch(new CookieCreatedEvent(
        cookieId: $cookieId,
        cookieName: $name->getValue(),
        cookiePrice: $price->getValue()
    ));
}
```

**❌ Else After Return:**
```php
public function isActive(): bool
{
    if ($this->isActive === true) {
        return true;
    } else {
        return false;
    }
}
```

**✅ Early Return (or direct return):**
```php
public function isActive(): bool
{
    return $this->isActive;
}
```

**❌ Magic Numbers in Value Object:**
```php
final readonly class CookieName
{
    private function __construct(private string $value)
    {
        if (mb_strlen($value) < 3) {  // Magic number!
            throw ValidationException::fieldTooShort('name', 3);
        }
        if (mb_strlen($value) > 100) {  // Magic number!
            throw ValidationException::fieldTooLong('name', 100);
        }
    }
}
```

**✅ Named Constants:**
```php
final readonly class CookieName
{
    private const MIN_LENGTH = 3;
    private const MAX_LENGTH = 100;

    private function __construct(private string $value)
    {
        $length = mb_strlen($value);

        if ($length < self::MIN_LENGTH) {
            throw ValidationException::fieldTooShort('name', self::MIN_LENGTH, $length);
        }
        if ($length > self::MAX_LENGTH) {
            throw ValidationException::fieldTooLong('name', self::MAX_LENGTH, $length);
        }
    }
}
```

**❌ Too Many Parameters (more than 3):**
```php
public function createCookie(string $name, string $desc, float $price, int $stock, bool $active): int
{
    // 5 parameters violates the "max 3 parameters" rule
}
```

**✅ Use Command DTO:**
```php
public function createCookie(CreateCookieCommand $command): int
{
    return $this->handler->handle($command);  // 1 parameter!
}

final readonly class CreateCookieCommand
{
    public function __construct(
        public string $name,
        public float $price,
        public int $stock,
        public bool $isActive = true
    ) {}
}
```
