# Property Addition Protocol

**MANDATORY PROTOCOL for adding properties to existing entities.**

---

## Quick Command

```bash
/add-property {Domain} {propertyName} {type}
```

Example: `/add-property Cookie flavor string`

---

## Pre-Addition Checklist

- [ ] Property name is camelCase (flavor, isGlutenFree)
- [ ] Property type determined (string, int, bool, float)
- [ ] Validation rules identified (min/max length, range, format)
- [ ] Determine if value object needed (complex validation = yes)
- [ ] Database column name (snake_case: is_gluten_free)
- [ ] Default value determined (if any)

---

## Decision: Value Object vs. Simple Property

**Use Value Object if:**
- Property has validation rules (min/max, format, regex)
- Property has business logic (calculations, comparisons)
- Property represents a domain concept (Money, Email, Address)

**Use Simple Property if:**
- Property is a basic boolean flag
- Property is a simple reference ID
- Property has no validation beyond type

---

## Files to Modify (20+ files)

### If Using Value Object (Recommended)

**Domain Layer (7-10 files):**
1. Create value object: `app/Domain/{Domain}/ValueObjects/{Entity}{Property}.php`
2. Update entity: `app/Domain/{Domain}/Entities/{Entity}.php`
3. Update CreateCommand: `app/Domain/{Domain}/Commands/Create{Entity}/Create{Entity}Command.php`
4. Update CreateHandler: `app/Domain/{Domain}/Commands/Create{Entity}/Create{Entity}Handler.php`
5. Update UpdateCommand: `app/Domain/{Domain}/Commands/Update{Entity}/Update{Entity}Command.php`
6. Update UpdateHandler: `app/Domain/{Domain}/Commands/Update{Entity}/Update{Entity}Handler.php`
7. Update relevant queries if property affects filtering

**Infrastructure Layer (2 files):**
8. Update repository: `app/Infrastructure/Persistence/Repositories/{Entity}Repository.php`
9. Update model: `app/Models/{Domain}/{Entity}Model.php`

**Database Layer (1 file):**
10. Create migration: `app/Database/Migrations/YYYY-MM-DD-HHMMSS_Add{Property}To{Entities}Table.php`

**Presentation Layer (3-4 files):**
11. Update create view: `app/Views/{entities}/create.php`
12. Update edit view: `app/Views/{entities}/edit.php`
13. Update show view: `app/Views/{entities}/show.php`
14. Update index view (if property displayed in list): `app/Views/{entities}/index.php`

**Test Layer (8-10 files):**
15. Create value object test: `tests/Unit/Domain/{Domain}/ValueObjects/{Entity}{Property}Test.php`
16. Update entity test: `tests/Unit/Domain/{Domain}/Entities/{Entity}Test.php`
17. Update CreateHandler test
18. Update UpdateHandler test
19. Update repository test
20. Update feature test
21. Update test factory: `tests/Support/Factories/{Entity}Factory.php`

---

## Step-by-Step Protocol

### Step 1: Create Value Object (If Needed)

```php
// app/Domain/{Domain}/ValueObjects/{Entity}{Property}.php

declare(strict_types=1);

namespace App\Domain\{Domain}\ValueObjects;

use App\Domain\Shared\Exceptions\ValidationException;

final readonly class {Entity}{Property}
{
    private const MIN_LENGTH = 3;
    private const MAX_LENGTH = 50;

    private function __construct(private string $value)
    {
        $length = strlen($value);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw ValidationException::invalidLength(
                '{property}',
                self::MIN_LENGTH,
                self::MAX_LENGTH,
                $length
            );
        }
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

**Specialists:** `ddd-specialist`, `php-specialist`

---

### Step 2: Update Entity

Add to constructor:
```php
private function __construct(
    // ... existing properties
    private {ValueObject} ${property},  // Add new property
) {
}
```

Add to `create()`:
```php
public static function create(
    // ... existing parameters
    {ValueObject} ${property},  // Add new parameter
): self {
    return new self(
        // ... existing arguments
        ${property}  // Pass new property
    );
}
```

Add to `reconstitute()`:
```php
public static function reconstitute(
    // ... existing parameters
    {ValueObject} ${property},  // Add new parameter
): self {
    return new self(
        // ... existing arguments
        ${property}
    );
}
```

Add getter:
```php
public function get{Property}(): {ValueObject}
{
    return $this->{property};
}
```

Add setter if mutable:
```php
public function update{Property}({ValueObject} $new{Property}): void
{
    $this->{property} = $new{Property};
}
```

**Specialists:** `ddd-specialist`, `clean-code-specialist`

---

### Step 3: Update Commands

**CreateCommand:**
```php
public function __construct(
    // ... existing properties
    public string ${property},  // Raw string from form
) {
}
```

**CreateHandler:**
```php
public function handle(Create{Entity}Command $command): int
{
    // Convert to value object
    ${property} = {Entity}{Property}::fromString($command->{property});

    ${entity} = {Entity}::create(
        // ... existing arguments
        {property}: ${property}
    );
}
```

**UpdateCommand & UpdateHandler:** Same pattern

**Specialists:** `cqrs-specialist`, `clean-code-specialist`

---

### Step 4: Update Repository

**Model - Add to $allowedFields:**
```php
protected $allowedFields = [
    // ... existing fields
    '{property}',
];
```

**Repository - Update save():**
```php
public function save({Entity} ${entity}): int
{
    $data = [
        // ... existing fields
        '{property}' => ${entity}->get{Property}()->getValue(),
    ];
}
```

**Repository - Update toDomainEntity():**
```php
/**
 * @param array{
 *     id: int,
 *     // ... existing fields
 *     {property}: string,
 * } $data
 */
private function toDomainEntity(array $data): {Entity}
{
    return {Entity}::reconstitute(
        // ... existing arguments
        {property}: {Entity}{Property}::fromString((string) $data['{property}']),
    );
}
```

**Specialists:** `php-specialist`, `phpstan-specialist`

---

### Step 5: Create Migration

```bash
php spark make:migration Add{Property}To{Entities}Table
```

```php
public function up(): void
{
    $this->forge->addColumn('{entities}', [
        '{property}' => [
            'type' => 'VARCHAR',
            'constraint' => 50,
            'null' => false,  // or true if optional
            'default' => '',  // if needed
            'after' => '{previous_field}',  // optional
        ],
    ]);
}

public function down(): void
{
    $this->forge->dropColumn('{entities}', '{property}');
}
```

**Run:** `php spark migrate`

**Specialist:** `codeigniter4-specialist`

---

### Step 6: Update Views

**create.php & edit.php:**
```php
<div class="mb-3">
    <label for="{property}" class="form-label">{Property} <span class="text-danger">*</span></label>
    <input type="text"
           class="form-control <?= session('errors.{property}') ? 'is-invalid' : '' ?>"
           id="{property}"
           name="{property}"
           value="<?= old('{property}', ${entity}->get{Property}()->getValue() ?? '') ?>"
           required>
    <?php if (session('errors.{property}')): ?>
        <div class="invalid-feedback"><?= esc(session('errors.{property}')) ?></div>
    <?php endif; ?>
</div>
```

**show.php:**
```php
<tr>
    <th>{Property}:</th>
    <td><?= esc(${entity}->get{Property}()->getValue()) ?></td>
</tr>
```

**index.php** (if shown in list):
```php
<td><?= esc(${entity}->get{Property}()->getValue()) ?></td>
```

---

### Step 7: Create/Update Tests

**Value Object Test:**
```php
final class {Entity}{Property}Test extends UnitTestCase
{
    public function test_can_create_with_valid_value(): void
    {
        ${property} = {Entity}{Property}::fromString('valid value');

        $this->assertInstanceOf({Entity}{Property}::class, ${property});
        $this->assertEquals('valid value', ${property}->getValue());
    }

    public function test_throws_exception_for_too_short(): void
    {
        $this->expectException(ValidationException::class);

        {Entity}{Property}::fromString('ab');  // Too short
    }

    public function test_throws_exception_for_too_long(): void
    {
        $this->expectException(ValidationException::class);

        {Entity}{Property}::fromString(str_repeat('a', 51));  // Too long
    }
}
```

**Update Entity Test:**
```php
public function test_create_with_new_property(): void
{
    ${entity} = {Entity}::create(
        // ... existing args
        {property}: {Entity}{Property}::fromString('test value')
    );

    $this->assertEquals('test value', ${entity}->get{Property}()->getValue());
}
```

**Update Handler Tests, Repository Tests, Feature Tests**

**Specialist:** `test-specialist`

**Quality Gate:** Maintain 90% coverage

---

### Step 8: Update Test Factory

```php
public static function create{Entity}(array $overrides = []): {Entity}
{
    return {Entity}::create(
        // ... existing arguments
        {property}: $overrides['{property}'] ?? {Entity}{Property}::fromString('default value'),
    );
}
```

---

## Validation Before Commit

```bash
# PHPStan
vendor/bin/phpstan analyse

# Slevomat
vendor/bin/phpcs

# Tests
vendor/bin/phpunit

# All checks
composer check
```

**All must pass with 0 errors/violations and 90%+ coverage.**

**Specialists:** `phpstan-specialist`, `slevomat-specialist`, `test-specialist`

---

## Complete Checklist

- [ ] Value object created (if needed) with validation
- [ ] Entity constructor updated
- [ ] Entity create() method updated
- [ ] Entity reconstitute() method updated
- [ ] Entity getter added
- [ ] CreateCommand updated
- [ ] CreateHandler updated
- [ ] UpdateCommand updated
- [ ] UpdateHandler updated
- [ ] Repository toDomainEntity() updated
- [ ] Repository save() updated
- [ ] Model $allowedFields updated
- [ ] Migration created and run
- [ ] create.php view updated
- [ ] edit.php view updated
- [ ] show.php view updated
- [ ] index.php view updated (if displayed)
- [ ] Value object test created
- [ ] Entity test updated
- [ ] Handler tests updated
- [ ] Repository test updated
- [ ] Feature test updated
- [ ] Test factory updated
- [ ] PHPStan passes (0 errors)
- [ ] Slevomat passes (0 violations)
- [ ] Tests pass (90%+ coverage)

---

**Use `/add-property {Domain} {property} {type}` to automate this protocol.**
