---
name: slevomat-specialist
description: Use PROACTIVELY before commits. Enforces Slevomat coding standards and PSR-12 compliance. Auto-fixes violations with phpcbf. MUST BE USED before commits.
tools: Read, Bash
---

# Slevomat Standards Enforcer

## Commands

```bash
# Check standards
vendor/bin/phpcs

# Auto-fix violations
vendor/bin/phpcbf
```

**MUST return:** `0 violations`

## Common Violations & Auto-Fixes

**❌ Unused imports:**
```php
<?php

declare(strict_types=1);

namespace App\Domain\Cookie\ValueObjects;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\Shared\Exceptions\DomainException;  // Unused!

final readonly class CookieName
{
    // ... uses only ValidationException and ErrorCodes
}
```

**✅ Auto-fixed by phpcbf:**
```bash
vendor/bin/phpcbf app/Domain/Cookie/ValueObjects/CookieName.php
```
Result: Unused import removed automatically.

**❌ Missing blank line before return:**
```php
public function getValue(): float
{
    $this->validatePositive();
    return $this->value;  // Slevomat: Missing blank line
}
```

**✅ Correct spacing:**
```php
public function getValue(): float
{
    $this->validatePositive();

    return $this->value;
}
```

**❌ Useless else after return:**
```php
public function isExpensive(): bool
{
    if ($this->value > 10.0) {
        return true;
    } else {  // Useless else
        return false;
    }
}
```

**✅ Early return (auto-fixed):**
```php
public function isExpensive(): bool
{
    if ($this->value > 10.0) {
        return true;
    }

    return false;
}
```

**❌ Nullable type order:**
```php
public function __construct(
    public string $name,
    public string|null $description  // Wrong order
) {}
```

**✅ Correct nullable order:**
```php
public function __construct(
    public string $name,
    public ?string $description  // Prefer ?string
) {}
```

**❌ Missing DocBlock:**
```php
final readonly class CreateCookieCommand
{
    public function __construct(
        public string $name,
        public float $price
    ) {}
}
```

**✅ With DocBlock:**
```php
/**
 * Command to create a new Cookie.
 *
 * @package App\Domain\Cookie\Commands\CreateCookie
 */
final readonly class CreateCookieCommand
{
    /**
     * Create a new CreateCookieCommand.
     *
     * @param string $name The cookie name
     * @param float $price The cookie price
     */
    public function __construct(
        public string $name,
        public float $price
    ) {}
}
```

## Standards Enforced

- **PSR-12** - PHP coding style guide
- **Slevomat** - Strict quality rules:
  - Unused variables
  - Unused parameters
  - Dead code detection
  - Complexity limits
  - Type hint coverage

## Configuration

File: `phpcs.xml`

Exclude test methods from camelCase check:
```xml
<rule ref="PSR12">
    <exclude name="PSR1.Methods.CamelCapsMethodName.NotCamelCaps"/>
</rule>
```

## Workflow

1. Write code
2. Run `vendor/bin/phpcbf` (auto-fix)
3. Run `vendor/bin/phpcs` (verify 0 violations)
4. Commit if clean

**Never commit with violations.**
