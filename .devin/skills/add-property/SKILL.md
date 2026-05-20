---
name: add-property
description: >
  Add a new property/field/column to an existing domain entity. Updates all
  20+ required files: value object, entity, commands, handlers, repository,
  migration, model, DTO, views, and tests. Use when the user asks to "add a
  field", "new column", "add property", or "update entity with new attribute".
---

# add-property

Add a property to an existing domain entity, touching every layer that
needs to know about it.

## Step 1 — Gather info

- **Domain name**: e.g., `Cookie`, `User`
- **Property name**: e.g., `flavor`, `expiryDate`
- **Type**: `string`, `int`, `float`, `bool`, `DateTimeImmutable`
- **Nullable?**: yes/no
- **Validation rules**: min/max length, range, format, etc.
- **Default value**: if any

## Step 2 — Create Value Object (if complex type)

If the property has validation beyond basic type checking, create a
value object:

File: `app/Domain/{Domain}/ValueObjects/{PropertyName}.php`

Reference: `app/Domain/Cookie/ValueObjects/CookieName.php`

Pattern: `final readonly class` with private constructor, static factory,
validation, `getValue()`, `equals()`.

## Step 3 — Update Entity

File: `app/Domain/{Domain}/Entities/{Domain}.php`

1. Add private property (or value object)
2. Add getter method
3. Update `create()` factory to accept the new parameter
4. Update constructor

## Step 4 — Update Commands

Files to update for each command that should include this property:

- `app/Domain/{Domain}/Commands/Create{Domain}/Create{Domain}Command.php`
  — add constructor parameter
- `app/Domain/{Domain}/Commands/Create{Domain}/Create{Domain}Handler.php`
  — pass to entity factory
- `app/Domain/{Domain}/Commands/Update{Domain}/Update{Domain}Command.php`
  — add constructor parameter
- `app/Domain/{Domain}/Commands/Update{Domain}/Update{Domain}Handler.php`
  — update entity

## Step 5 — Update DTO

File: `app/Domain/{Domain}/DTOs/{Domain}DTO.php`

Add public property and update `fromEntity()` factory.

## Step 6 — Update Repository + Model

1. Model: `app/Models/{Domain}/{Domain}Model.php` — add to `$allowedFields`
2. Repository: update `toEntity()` / `toPersistence()` mapping

## Step 7 — Create Migration

```bash
php spark make:migration Add{Property}To{Domain}sTable
```

Add the column with appropriate type, nullable, default.

## Step 8 — Update Views

Update form views to include the new field:
- `app/Views/{domain}/create.php`
- `app/Views/{domain}/edit.php`
- `app/Views/{domain}/index.php` (if displayed in list)

## Step 9 — Update Controller

Update the controller to pass the new field to commands:
- `app/Controllers/Domain/{Domain}/{Domain}Controller.php`

## Step 10 — Update Tests

1. **Value object tests** (if created):
   `tests/Unit/Domain/{Domain}/ValueObjects/{PropertyName}Test.php`
2. **Entity tests**: update factory calls and add getter test
3. **Command handler tests**: update command construction
4. **Integration tests**: verify persistence round-trip
5. **Feature tests**: update HTTP request payloads
6. **Factory**: update test factory to include new field

## Step 11 — Verify

```bash
composer check
```

Common failures:
- PHPStan: missing type hint on new property/method
- PHPCS: missing DocBlock on new public method
- Tests: factory not updated with new required parameter
- Coverage: new code paths without test coverage
