---
name: phpstan-specialist
description: Use PROACTIVELY before any code commit. Enforces PHPStan Level 8 compliance with zero errors. Checks type annotations, null safety, array shapes, and strict comparisons. MUST BE USED before commits.
tools: Read, Bash
---

# PHPStan Level 8 Enforcer

## Command

```bash
vendor/bin/phpstan analyse --level=8
```

**MUST return:** `0 errors`

## Requirements

**Array Type Annotations:**
```php
/**
 * @param array{id: int, name: string, price: float} $data
 * @return array<int, Cookie>
 */
```

**Null Safety:**
- Check `preg_replace` returns: `if ($result === null)`
- Use strict comparisons: `===` not `==`
- Cast database values: `(int) $data['id']`

**Common Violations & Fixes:**

**❌ Offset access on mixed (array from database):**
```php
private function mapToCookie(array $data): Cookie
{
    return Cookie::reconstitute(
        id: $data['id'],  // PHPStan error: Cannot access offset 'id' on mixed
        name: CookieName::fromString($data['name']),
        price: CookiePrice::fromFloat($data['price'])
    );
}
```

**✅ With array shape annotation:**
```php
/**
 * @param array{id: int, name: string, price: float, stock: int} $data
 */
private function mapToCookie(array $data): Cookie
{
    return Cookie::reconstitute(
        id: (int) $data['id'],
        name: CookieName::fromString($data['name']),
        description: $data['description'],
        price: CookiePrice::fromFloat((float) $data['price']),
        stock: (int) $data['stock']
    );
}
```

**❌ Database query returns mixed:**
```php
public function findById(int $id): ?Cookie
{
    $result = $this->db->query('SELECT * FROM cookies WHERE id = ?', [$id]);
    $data = $result->getRow();  // PHPStan: Method getRow() returns mixed

    if ($data === null) {
        return null;
    }

    return $this->mapToCookie($data);  // Error: mixed passed to array
}
```

**✅ Cast to array with shape annotation:**
```php
public function findById(int $id): ?Cookie
{
    $result = $this->db->query('SELECT * FROM cookies WHERE id = ?', [$id]);
    $data = $result->getRowArray();  // Returns array|null

    if ($data === null) {
        return null;
    }

    return $this->mapToCookie($data);  // Now passes with @param annotation
}

/**
 * @param array{id: int, name: string, price: float, stock: int} $data
 */
private function mapToCookie(array $data): Cookie { /* ... */ }
```

**❌ preg_replace might return null:**
```php
public function sanitizeName(string $input): string
{
    $cleaned = preg_replace('/[^a-zA-Z0-9 ]/', '', $input);
    return trim($cleaned);  // PHPStan error: Argument of type string|null not accepted
}
```

**✅ Check for null before using:**
```php
public function sanitizeName(string $input): string
{
    $cleaned = preg_replace('/[^a-zA-Z0-9 ]/', '', $input);

    if ($cleaned === null) {
        throw new ValidationException('Invalid input for sanitization');
    }

    return trim($cleaned);
}
```

**❌ Array return needs generic annotation:**
```php
public function getAllActive(): array  // PHPStan: Array has no generic type
{
    return $this->repository->findAllActive();
}
```

**✅ Generic array annotation:**
```php
/**
 * Get all active cookies.
 *
 * @return array<int, Cookie>
 */
public function getAllActive(): array
{
    return $this->repository->findAllActive();
}
```

## Clear Cache

```bash
vendor/bin/phpstan clear-result-cache
```
