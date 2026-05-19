# Modifying Entity Properties - Complete Guide

This guide provides a comprehensive checklist of all files that need to be modified when adding or removing properties/fields from a domain entity.

## Quick Start (AI-Optimized Approach)

### Automated Property Addition (Recommended)

The **fastest and most reliable** way to add a property to an entity is using the AI agent system:

**Option 1: Slash Command** (Simplest)
```bash
/add-property Cookie category string
```

**Option 2: Request Skill Directly**
```
Use property-addition skill to add category property to Cookie domain
```

This automated approach:
- ✅ Updates 20+ files across all layers (Domain, Infrastructure, Application, Presentation)
- ✅ Creates Value Object if needed (with validation)
- ✅ Updates Entity (constructor, create, reconstitute, update, getter)
- ✅ Updates Commands and Handlers
- ✅ Updates Repository and Model
- ✅ Updates Controller and all Views
- ✅ Creates comprehensive tests (Unit, Integration, Feature)
- ✅ Validates with PHPStan Level 8 and Slevomat
- ✅ Maintains 90%+ test coverage
- ✅ Completes in 2-3 minutes vs 20+ minutes manual

**For details, see:** `.claude/skills/property-addition/SKILL.md` and `.claude/documentation/PROPERTY_ADDITION_PROTOCOL.md`

---

## Manual Modification (Understanding the Architecture)

The sections below explain the manual steps for adding a property. This is provided for:
- Understanding the layered architecture
- Customizing beyond the standard patterns
- Learning which files are affected by property changes

**Note:** Manual modification requires updating 20+ files and is error-prone. The automated approach is strongly recommended.

## Example: Adding a New Property

Let's use a concrete example: **Adding a `category` field to the Cookie entity**

### Required Changes Checklist

#### ✅ 1. Database Layer

**File:** `app/Database/Migrations/YYYY-MM-DD-HHMMSS_AddCategoryToCookies.php`

Create a new migration:
```bash
php spark make:migration AddCategoryToCookies
```

```php
public function up(): void
{
    $this->forge->addColumn('cookies', [
        'category' => [
            'type' => 'VARCHAR',
            'constraint' => 50,
            'null' => true,
            'after' => 'description'
        ]
    ]);
}

public function down(): void
{
    $this->forge->dropColumn('cookies', 'category');
}
```

Run migration:
```bash
php spark migrate
```

---

#### ✅ 2. Domain Layer - Value Object (if needed)

**File:** `app/Domain/Cookie/ValueObjects/CookieCategory.php` (NEW)

If the property has validation rules, create a Value Object:

```php
<?php
declare(strict_types=1);

namespace App\Domain\Cookie\Entities;

use App\Domain\Shared\Exceptions\ValidationException;

final readonly class CookieCategory
{
    private const VALID_CATEGORIES = ['chocolate', 'fruit', 'nut', 'seasonal'];

    private string $value;

    private function __construct(string $category)
    {
        $normalized = trim(strtolower($category));

        if (!in_array($normalized, self::VALID_CATEGORIES, true)) {
            throw ValidationException::invalidFormat(
                'category',
                'Must be one of: ' . implode(', ', self::VALID_CATEGORIES)
            );
        }

        $this->value = $normalized;
    }

    public static function fromString(string $category): self
    {
        return new self($category);
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
```

---

#### ✅ 3. Domain Layer - Entity

**File:** `app/Domain/Cookie/Entities/Cookie.php`

Add the property and update methods:

```php
// Add property
private ?CookieCategory $category;

// Update constructor
private function __construct(
    CookieName $name,
    ?string $description,
    CookiePrice $price,
    int $stock,
    bool $isActive,
    ?CookieCategory $category = null  // ADD THIS
) {
    // ... existing code ...
    $this->category = $category;  // ADD THIS
}

// Update create() factory method
public static function create(
    CookieName $name,
    ?string $description,
    CookiePrice $price,
    int $stock,
    bool $isActive,
    ?CookieCategory $category = null  // ADD THIS
): self {
    return new self($name, $description, $price, $stock, $isActive, $category);
}

// Update reconstitute() factory method
public static function reconstitute(
    int $id,
    CookieName $name,
    ?string $description,
    CookiePrice $price,
    int $stock,
    bool $isActive,
    string $createdAt,
    string $updatedAt,
    ?string $deletedAt = null,
    ?CookieCategory $category = null  // ADD THIS
): self {
    $cookie = new self($name, $description, $price, $stock, $isActive, $category);
    // ... rest of method ...
}

// Update update() method
public function update(
    CookieName $name,
    ?string $description,
    CookiePrice $price,
    int $stock,
    bool $isActive,
    ?CookieCategory $category  // ADD THIS
): void {
    // ... existing code ...
    $this->category = $category;  // ADD THIS
}

// Add getter
public function getCategory(): ?CookieCategory
{
    return $this->category;
}
```

---

#### ✅ 4. Infrastructure Layer - Model

**File:** `app/Models/Cookie/CookieModel.php`

Add to allowed fields and validation:

```php
protected $allowedFields = [
    'name',
    'description',
    'price',
    'stock',
    'is_active',
    'category',  // ADD THIS
];

protected $validationRules = [
    // ... existing rules ...
    'category' => 'permit_empty|in_list[chocolate,fruit,nut,seasonal]',  // ADD THIS
];
```

---

#### ✅ 5. Infrastructure Layer - Repository

**File:** `app/Models/Cookie/CookieRepository.php`

Update save() and toDomainEntity() methods:

```php
public function save(Cookie $cookie): int
{
    $data = [
        'name' => $cookie->getName()->getValue(),
        'description' => $cookie->getDescription(),
        'price' => $cookie->getPrice()->getValue(),
        'stock' => $cookie->getStock(),
        'is_active' => $cookie->getIsActive() ? 1 : 0,
        'category' => $cookie->getCategory()?->getValue(),  // ADD THIS
    ];
    // ... rest of method ...
}

private function toDomainEntity(array $data): Cookie
{
    return Cookie::reconstitute(
        // ... existing parameters ...
        category: isset($data['category'])
            ? CookieCategory::fromString($data['category'])
            : null  // ADD THIS
    );
}
```

---

#### ✅ 6. Application Layer - Commands

**File:** `app/Domain/Cookie/Commands/CreateCookie/CreateCookieCommand.php`

Add property to command DTO:

```php
public function __construct(
    public string $name,
    public ?string $description,
    public float $price,
    public int $stock,
    public bool $isActive,
    public ?string $category = null  // ADD THIS
) {
}
```

**File:** `app/Domain/Cookie/Commands/CreateCookie/CreateCookieHandler.php`

Use the new property:

```php
public function handle(CreateCookieCommand $command): int
{
    $name = CookieName::fromString($command->name);
    $price = CookiePrice::fromFloat($command->price);
    $category = $command->category !== null
        ? CookieCategory::fromString($command->category)
        : null;  // ADD THIS

    // ... existing validation ...

    $cookie = Cookie::create(
        name: $name,
        description: $command->description,
        price: $price,
        stock: $command->stock,
        isActive: $command->isActive,
        category: $category  // ADD THIS
    );

    // ... rest of method ...
}
```

**File:** `app/Domain/Cookie/Commands/UpdateCookie/UpdateCookieCommand.php`

Add the same property as CreateCookieCommand.

**File:** `app/Domain/Cookie/Commands/UpdateCookie/UpdateCookieHandler.php`

Add the same logic as CreateCookieHandler.

---

#### ✅ 7. Application Layer - Queries

**No changes needed** unless you want to filter by the new property.

Optional: Add filter to `GetCookiesPaginatedQuery`:

```php
public function __construct(
    public int $page = 1,
    public int $perPage = 20,
    public ?string $searchTerm = null,
    public bool $includeInactive = false,
    public ?string $categoryFilter = null  // ADD THIS IF FILTERING
) {
}
```

---

#### ✅ 8. Presentation Layer - Controller

**File:** `app/Controllers/Domain/Cookie/CookieController.php`

Update store() and update() methods:

```php
public function store(): RedirectResponse
{
    // ...
    $command = new CreateCookieCommand(
        name: $this->request->getPost('name') ?? '',
        description: $this->request->getPost('description'),
        price: (float) ($this->request->getPost('price') ?? 0),
        stock: (int) ($this->request->getPost('stock') ?? 0),
        isActive: (bool) $this->request->getPost('is_active'),
        category: $this->request->getPost('category')  // ADD THIS
    );
    // ...
}
```

---

#### ✅ 9. Presentation Layer - Views

**File:** `app/Views/cookies/create.php`

Add form field:

```html
<div class="mb-3">
    <label for="category" class="form-label">Category</label>
    <select class="form-select" id="category" name="category">
        <option value="">-- Select Category --</option>
        <option value="chocolate">Chocolate</option>
        <option value="fruit">Fruit</option>
        <option value="nut">Nut</option>
        <option value="seasonal">Seasonal</option>
    </select>
</div>
```

**File:** `app/Views/cookies/edit.php`

Add the same field with selected value:

```html
<option value="chocolate" <?= $cookie->getCategory()?->getValue() === 'chocolate' ? 'selected' : '' ?>>Chocolate</option>
```

**File:** `app/Views/cookies/show.php`

Display the new field:

```html
<p><strong>Category:</strong> <?= esc($cookie->getCategory()?->getValue() ?? 'N/A') ?></p>
```

**File:** `app/Views/cookies/index.php`

Add column to table:

```html
<th>Category</th>
<!-- In loop: -->
<td><?= esc($cookie->getCategory()?->getValue() ?? 'N/A') ?></td>
```

---

#### ✅ 10. Testing Layer

Add comprehensive tests for the new property across all test levels.

##### 10.1 Unit Tests - Value Object (if created)

**File:** `tests/Unit/Domain/Cookie/ValueObjects/CookieCategoryTest.php` (NEW)

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\ValueObjects;

use App\Domain\Cookie\ValueObjects\CookieCategory;
use App\Domain\Shared\Exceptions\ValidationException;
use Tests\Support\UnitTestCase;

final class CookieCategoryTest extends UnitTestCase
{
    public function test_can_create_with_valid_category(): void
    {
        $category = CookieCategory::fromString('chocolate');

        $this->assertInstanceOf(CookieCategory::class, $category);
        $this->assertEquals('chocolate', $category->getValue());
    }

    public function test_throws_exception_for_invalid_category(): void
    {
        $this->expectException(ValidationException::class);

        CookieCategory::fromString('invalid');
    }

    public function test_normalizes_to_lowercase(): void
    {
        $category = CookieCategory::fromString('CHOCOLATE');

        $this->assertEquals('chocolate', $category->getValue());
    }
}
```

##### 10.2 Unit Tests - Entity

**File:** `tests/Unit/Domain/Cookie/Entities/CookieTest.php`

Add tests for the new property:

```php
public function test_create_with_category(): void
{
    $category = CookieCategory::fromString('chocolate');

    $cookie = Cookie::create(
        name: CookieName::fromString('Test Cookie'),
        description: 'Test description',
        price: CookiePrice::fromFloat(2.99),
        stock: 10,
        isActive: true,
        category: $category
    );

    $this->assertEquals('chocolate', $cookie->getCategory()->getValue());
}

public function test_create_without_category(): void
{
    $cookie = Cookie::create(
        name: CookieName::fromString('Test Cookie'),
        description: 'Test description',
        price: CookiePrice::fromFloat(2.99),
        stock: 10,
        isActive: true,
        category: null
    );

    $this->assertNull($cookie->getCategory());
}

public function test_update_changes_category(): void
{
    $cookie = Cookie::create(/* ... */);
    $newCategory = CookieCategory::fromString('fruit');

    $cookie->update(
        name: CookieName::fromString('Test Cookie'),
        description: 'Test description',
        price: CookiePrice::fromFloat(2.99),
        stock: 10,
        isActive: true,
        category: $newCategory
    );

    $this->assertEquals('fruit', $cookie->getCategory()->getValue());
}

public function test_reconstitute_with_category(): void
{
    $cookie = Cookie::reconstitute(
        id: 1,
        name: CookieName::fromString('Test Cookie'),
        description: 'Test description',
        price: CookiePrice::fromFloat(2.99),
        stock: 10,
        isActive: true,
        createdAt: '2024-01-01 00:00:00',
        updatedAt: '2024-01-01 00:00:00',
        deletedAt: null,
        category: CookieCategory::fromString('chocolate')
    );

    $this->assertEquals('chocolate', $cookie->getCategory()->getValue());
}
```

##### 10.3 Unit Tests - Command Handlers

**File:** `tests/Unit/Domain/Cookie/Commands/CreateCookieHandlerTest.php`

Update tests to include category:

```php
public function test_creates_cookie_with_category(): void
{
    $repository = $this->createMock(CookieRepository::class);
    $eventDispatcher = $this->createMock(EventDispatcher::class);

    $repository->expects($this->once())
        ->method('save')
        ->with($this->callback(function ($cookie) {
            return $cookie->getCategory()->getValue() === 'chocolate';
        }))
        ->willReturn(1);

    $handler = new CreateCookieHandler($repository, $eventDispatcher);
    $command = new CreateCookieCommand(
        name: 'Test Cookie',
        description: 'Test',
        price: 2.99,
        stock: 10,
        isActive: true,
        category: 'chocolate'
    );

    $cookieId = $handler->handle($command);

    $this->assertEquals(1, $cookieId);
}
```

**File:** `tests/Unit/Domain/Cookie/Commands/UpdateCookieHandlerTest.php`

Add similar test for update handler.

##### 10.4 Integration Tests - Repository

**File:** `tests/Integration/Repositories/CookieRepositoryTest.php`

Add tests for category persistence:

```php
public function test_save_persists_category(): void
{
    $cookie = Cookie::create(
        name: CookieName::fromString('Test Cookie'),
        description: 'Test',
        price: CookiePrice::fromFloat(2.99),
        stock: 10,
        isActive: true,
        category: CookieCategory::fromString('chocolate')
    );

    $id = $this->repository->save($cookie);

    $this->assertDatabaseHas('cookies', [
        'id' => $id,
        'category' => 'chocolate',
    ]);
}

public function test_find_by_id_loads_category(): void
{
    $cookie = Cookie::create(/* with category */);
    $id = $this->repository->save($cookie);

    $found = $this->repository->findById($id);

    $this->assertNotNull($found->getCategory());
    $this->assertEquals('chocolate', $found->getCategory()->getValue());
}

public function test_save_handles_null_category(): void
{
    $cookie = Cookie::create(
        /* ... */
        category: null
    );

    $id = $this->repository->save($cookie);

    $this->assertDatabaseHas('cookies', [
        'id' => $id,
        'category' => null,
    ]);
}
```

##### 10.5 Feature Tests - Controller

**File:** `tests/Feature/Cookie/CookieCrudTest.php`

Add tests for category in forms:

```php
public function test_store_creates_cookie_with_category(): void
{
    $result = $this->post('/cookies', [
        'name' => 'Test Cookie',
        'description' => 'Test',
        'price' => '2.99',
        'stock' => '10',
        'is_active' => '1',
        'category' => 'chocolate',
    ]);

    $result->assertRedirect();
    $this->assertDatabaseHas('cookies', [
        'name' => 'Test Cookie',
        'category' => 'chocolate',
    ]);
}

public function test_update_changes_category(): void
{
    $cookie = CookieFactory::createCookie([
        'category' => CookieCategory::fromString('chocolate'),
    ]);
    $id = $this->cookieRepository->save($cookie);

    $result = $this->post("/cookies/{$id}", [
        'name' => 'Updated Cookie',
        'description' => 'Test',
        'price' => '3.99',
        'stock' => '20',
        'is_active' => '1',
        'category' => 'fruit',
    ]);

    $result->assertRedirect();
    $this->assertDatabaseHas('cookies', [
        'id' => $id,
        'category' => 'fruit',
    ]);
}

public function test_show_displays_category(): void
{
    $cookie = CookieFactory::createCookie([
        'category' => CookieCategory::fromString('chocolate'),
    ]);
    $id = $this->cookieRepository->save($cookie);

    $result = $this->get("/cookies/{$id}");

    $result->assertOK();
    $result->assertSee('chocolate');
}

public function test_create_form_has_category_field(): void
{
    $result = $this->get('/cookies/create');

    $result->assertOK();
    $result->assertSee('name="category"');
}

public function test_edit_form_shows_selected_category(): void
{
    $cookie = CookieFactory::createCookie([
        'category' => CookieCategory::fromString('chocolate'),
    ]);
    $id = $this->cookieRepository->save($cookie);

    $result = $this->get("/cookies/{$id}/edit");

    $result->assertOK();
    $result->assertSee('value="chocolate" selected');
}
```

##### 10.6 Test Factory

**File:** `tests/Support/Factories/CookieFactory.php`

Update factory to support the new property:

```php
public static function createCookie(array $overrides = []): Cookie
{
    return Cookie::create(
        name: $overrides['name'] ?? CookieName::fromString('Test Cookie'),
        description: $overrides['description'] ?? 'Test description',
        price: $overrides['price'] ?? CookiePrice::fromFloat(2.99),
        stock: $overrides['stock'] ?? 10,
        isActive: $overrides['isActive'] ?? true,
        category: $overrides['category'] ?? null  // ADD THIS
    );
}
```

##### 10.7 Test Checklist

When adding a property, ensure these tests are created/updated:

**Unit Tests:**
- [ ] Create value object test file (if new value object)
- [ ] Test value object validation rules
- [ ] Test value object edge cases
- [ ] Update entity `create()` tests
- [ ] Update entity `reconstitute()` tests
- [ ] Update entity `update()` tests
- [ ] Update entity getter tests
- [ ] Update CreateCookieHandler tests
- [ ] Update UpdateCookieHandler tests
- [ ] Test null/optional property handling

**Integration Tests:**
- [ ] Test repository save with new property
- [ ] Test repository find loads new property
- [ ] Test repository save with null property
- [ ] Test repository update preserves property

**Feature Tests:**
- [ ] Test create form displays field
- [ ] Test store action saves property
- [ ] Test edit form shows current value
- [ ] Test update action changes property
- [ ] Test show page displays property
- [ ] Test index page displays property
- [ ] Test validation errors for property

**Test Factory:**
- [ ] Add property to factory method
- [ ] Support overriding property in tests

**Run Tests:**
```bash
# Run all tests
vendor/bin/phpunit

# Run only updated tests
vendor/bin/phpunit tests/Unit/Domain/Cookie/ValueObjects/CookieCategoryTest.php
vendor/bin/phpunit tests/Unit/Domain/Cookie/Entities/CookieTest.php
vendor/bin/phpunit tests/Integration/Repositories/CookieRepositoryTest.php
vendor/bin/phpunit tests/Feature/Cookie/CookieCrudTest.php

# Verify coverage
vendor/bin/phpunit --coverage-text
```

---

#### ✅ 11. Seeder (Optional)

**File:** `app/Database/Seeds/CookieSeeder.php`

Add category values to sample data:

```php
$data = [
    [
        'name' => 'Chocolate Chip',
        // ... existing fields ...
        'category' => 'chocolate',  // ADD THIS
    ],
    // ... other cookies ...
];
```

---

## Summary Checklist

When adding/removing a property, modify these files in order:

1. **Database**
   - [ ] Create migration
   - [ ] Run migration

2. **Domain Layer**
   - [ ] Create Value Object (if needed)
   - [ ] Update Entity (property, constructor, factories, getter)

3. **Infrastructure Layer**
   - [ ] Update Model (allowedFields, validation)
   - [ ] Update Repository (save(), toDomainEntity())

4. **Application Layer**
   - [ ] Update Commands (CreateCommand, UpdateCommand)
   - [ ] Update Command Handlers (CreateHandler, UpdateHandler)
   - [ ] Update Queries (if filtering needed)
   - [ ] Update Query Handlers (if filtering needed)

5. **Presentation Layer**
   - [ ] Update Controller (store(), update())
   - [ ] Update Views (create, edit, show, index)

6. **Testing Layer** (See Section 10 for detailed examples)
   - [ ] Create/update value object tests (if applicable)
   - [ ] Update entity unit tests (create, reconstitute, update, getter)
   - [ ] Update command handler unit tests
   - [ ] Update query handler unit tests (if applicable)
   - [ ] Update repository integration tests (save, find, update)
   - [ ] Update controller feature tests (all CRUD operations)
   - [ ] Update test factory to support new property
   - [ ] Run tests and verify all pass

7. **Data Layer**
   - [ ] Update Seeder (optional)

---

## Removing a Property

Follow the same checklist in reverse:

1. Remove from views
2. Remove from controller
3. Remove from commands and handlers
4. Remove from repository
5. Remove from model
6. Remove from entity
7. Delete Value Object (if exists)
8. Create migration to drop column
9. Update tests

---

## Tips

- **Always start with the database** migration
- **Work from the inside out**: Domain → Infrastructure → Application → Presentation
- **Don't forget tests!** Update tests alongside production code
- **Run migrations** before testing
- **Use PHPStan** to find missing updates: `composer phpstan`
- **Run tests** after each layer: `composer test`

---

## Quick Reference Table

| Layer | Files to Modify |
|-------|----------------|
| **Database** | Migration |
| **Domain** | Value Object (optional), Entity |
| **Infrastructure** | Model, Repository |
| **Application** | Commands, Command Handlers, Queries (optional), Query Handlers (optional) |
| **Presentation** | Controller, Views (create, edit, show, index) |
| **Testing** | All test files for affected classes |
| **Data** | Seeder (optional) |

---

## Common Mistakes to Avoid

❌ **Forgetting to update `reconstitute()` method** in Entity
❌ **Not adding to `allowedFields` in Model** (insert/update will fail)
❌ **Forgetting to update both Create AND Update handlers**
❌ **Not handling nullable properties** properly (null checks, ??)
❌ **Forgetting to update views** (property won't display)
❌ **Not running migrations** before testing

---

This guide ensures you never miss a file when modifying entities!
