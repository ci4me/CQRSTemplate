# Testing Guidelines

**Comprehensive testing strategy for CQRS/DDD architecture.**

---

## Test Pyramid

**Distribution:**
- **70% Unit Tests** - Fast, isolated, no dependencies
- **20% Integration Tests** - Database interactions
- **10% Feature Tests** - Full HTTP request flow

**Minimum Coverage:** 90%

---

## Unit Tests (70%)

### Value Objects

**Test:**
- Valid creation
- Invalid values (each validation rule)
- Immutability
- Equality
- Methods (format, add, subtract, etc.)

```php
final class CookiePriceTest extends UnitTestCase
{
    public function test_can_create_with_valid_price(): void
    {
        $price = CookiePrice::fromFloat(2.99);

        $this->assertInstanceOf(CookiePrice::class, $price);
        $this->assertEquals(2.99, $price->getValue());
    }

    public function test_throws_exception_for_price_too_low(): void
    {
        $this->expectException(ValidationException::class);
        CookiePrice::fromFloat(0.00);
    }

    public function test_is_immutable(): void
    {
        $price = CookiePrice::fromFloat(2.99);
        $newPrice = $price->add(CookiePrice::fromFloat(1.00));

        $this->assertEquals(2.99, $price->getValue());
        $this->assertEquals(3.99, $newPrice->getValue());
    }
}
```

### Entities

**Test:**
- Factory methods (create, reconstitute)
- Business methods
- Invariants
- Getters

```php
final class CookieTest extends UnitTestCase
{
    public function test_create_new_cookie(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Chocolate Chip'),
            price: CookiePrice::fromFloat(2.99),
            stock: 100
        );

        $this->assertNull($cookie->getId());  // No ID until persisted
        $this->assertEquals('Chocolate Chip', $cookie->getName()->getValue());
    }

    public function test_cannot_decrease_stock_below_zero(): void
    {
        $cookie = Cookie::create(/* ... */, stock: 5);

        $this->expectException(ValidationException::class);
        $cookie->decreaseStock(10);
    }
}
```

### Handlers

**Test with mocks:**
- Happy path
- Validation errors
- Business rule violations
- Event dispatching

```php
final class CreateCookieHandlerTest extends UnitTestCase
{
    public function test_creates_cookie_successfully(): void
    {
        $repository = $this->createMock(CookieRepository::class);
        $eventDispatcher = $this->createMock(EventDispatcher::class);

        $repository->expects($this->once())
            ->method('save')
            ->willReturn(1);

        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(CookieCreatedEvent::class));

        $handler = new CreateCookieHandler($repository, $eventDispatcher);
        $command = new CreateCookieCommand(
            name: 'Chocolate Chip',
            price: '2.99',
            stock: 100
        );

        $cookieId = $handler->handle($command);

        $this->assertEquals(1, $cookieId);
    }
}
```

---

## Integration Tests (20%)

**Test repository with real database:**
- Save (insert & update)
- FindById
- FindAll
- Pagination
- Delete
- Domain entity mapping

```php
final class CookieRepositoryTest extends IntegrationTestCase
{
    private CookieRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new CookieRepository();
    }

    public function test_save_inserts_new_cookie(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Test Cookie'),
            price: CookiePrice::fromFloat(2.99),
            stock: 100
        );

        $cookieId = $this->repository->save($cookie);

        $this->assertGreaterThan(0, $cookieId);
        $this->assertDatabaseHas('cookies', [
            'id' => $cookieId,
            'name' => 'Test Cookie',
        ]);
    }

    public function test_find_by_id_returns_domain_entity(): void
    {
        $cookie = Cookie::create(
            name: CookieName::fromString('Test'),
            price: CookiePrice::fromFloat(2.99),
            stock: 100
        );
        $cookieId = $this->repository->save($cookie);

        $found = $this->repository->findById($cookieId);

        $this->assertInstanceOf(Cookie::class, $found);
        $this->assertEquals('Test', $found->getName()->getValue());
        $this->assertEquals(2.99, $found->getPrice()->getValue());
    }

    public function test_delete_removes_cookie(): void
    {
        $cookie = Cookie::create(/* ... */);
        $cookieId = $this->repository->save($cookie);

        $result = $this->repository->delete($cookieId);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('cookies', [
            'id' => $cookieId,
            'deleted_at' => null,
        ]);
    }
}
```

---

## Feature Tests (10%)

**Test complete HTTP flows:**
- Index/list page
- Create form & submission
- Show/detail page
- Edit form & submission
- Delete action
- Validation errors
- Flash messages

```php
final class CookieCrudTest extends FeatureTestCase
{
    public function test_index_displays_cookies_list(): void
    {
        $result = $this->get('/cookies');

        $result->assertOK();
        $result->assertSee('cookies/index');
    }

    public function test_store_creates_new_cookie_successfully(): void
    {
        $result = $this->post('/cookies', [
            'name' => 'Chocolate Chip',
            'price' => '2.99',
            'stock' => 100,
            'is_active' => '1',
        ]);

        $result->assertRedirect();
        $this->assertFlashMessage('success');
        $this->assertDatabaseHas('cookies', [
            'name' => 'Chocolate Chip',
        ]);
    }

    public function test_store_handles_validation_errors(): void
    {
        $result = $this->post('/cookies', [
            'name' => 'AB',  // Too short
            'price' => '0.00',  // Too low
            'stock' => -1,  // Negative
        ]);

        $result->assertRedirect();
        $this->assertHasValidationErrors();
    }

    public function test_update_modifies_existing_cookie(): void
    {
        $cookie = CookieFactory::createCookie();
        $cookieId = $this->cookieRepository->save($cookie);

        $result = $this->post("/cookies/{$cookieId}", [
            'name' => 'Updated Name',
            'price' => '3.49',
            'stock' => 50,
        ]);

        $result->assertRedirect();
        $this->assertDatabaseHas('cookies', [
            'id' => $cookieId,
            'name' => 'Updated Name',
        ]);
    }

    public function test_delete_removes_cookie(): void
    {
        $cookie = CookieFactory::createCookie();
        $cookieId = $this->cookieRepository->save($cookie);

        $result = $this->post("/cookies/{$cookieId}/delete");

        $result->assertRedirect();
        $this->assertFlashMessage('success');
        $this->assertDatabaseMissing('cookies', [
            'id' => $cookieId,
            'deleted_at' => null,
        ]);
    }
}
```

---

## Test Factories

**Purpose:** Create test data easily

```php
final class CookieFactory
{
    /**
     * @param array<string, mixed> $overrides
     */
    public static function createCookie(array $overrides = []): Cookie
    {
        return Cookie::create(
            name: $overrides['name'] ?? CookieName::fromString('Test Cookie'),
            price: $overrides['price'] ?? CookiePrice::fromFloat(2.99),
            stock: $overrides['stock'] ?? 100,
            description: $overrides['description'] ?? 'Test description',
            isActive: $overrides['isActive'] ?? true
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function createCookieWithId(array $overrides = []): Cookie
    {
        return Cookie::reconstitute(
            id: $overrides['id'] ?? 1,
            name: $overrides['name'] ?? CookieName::fromString('Test Cookie'),
            price: $overrides['price'] ?? CookiePrice::fromFloat(2.99),
            stock: $overrides['stock'] ?? 100,
            description: $overrides['description'] ?? null,
            isActive: $overrides['isActive'] ?? true,
            createdAt: $overrides['createdAt'] ?? date('Y-m-d H:i:s'),
            updatedAt: $overrides['updatedAt'] ?? date('Y-m-d H:i:s'),
            deletedAt: $overrides['deletedAt'] ?? null
        );
    }
}
```

---

## Running Tests

```bash
# All tests
vendor/bin/phpunit

# With coverage
vendor/bin/phpunit --coverage-text

# Specific test file
vendor/bin/phpunit tests/Unit/Domain/Cookie/ValueObjects/CookiePriceTest.php

# Specific test method
vendor/bin/phpunit --filter=test_can_create_with_valid_price

# By group
vendor/bin/phpunit --group=unit

# Readable output
vendor/bin/phpunit --testdox

# Stop on failure
vendor/bin/phpunit --stop-on-failure

# Coverage HTML report
vendor/bin/phpunit --coverage-html coverage
```

---

## Test Checklist for New Domain

**Unit Tests:**
- [ ] All value objects tested (creation, validation, methods)
- [ ] Entity create() tested
- [ ] Entity reconstitute() tested
- [ ] Entity business methods tested
- [ ] All command handlers tested with mocks
- [ ] All query handlers tested with mocks
- [ ] Event creation tested
- [ ] Event handler invocation tested

**Integration Tests:**
- [ ] Repository save() (insert) tested
- [ ] Repository save() (update) tested
- [ ] Repository findById() tested
- [ ] Repository findAll() tested
- [ ] Repository pagination tested
- [ ] Repository delete() tested
- [ ] Domain entity mapping tested

**Feature Tests:**
- [ ] Index page loads
- [ ] Create form displays
- [ ] Store creates record
- [ ] Store handles validation errors
- [ ] Show page displays entity
- [ ] Edit form displays with data
- [ ] Update modifies record
- [ ] Update handles validation errors
- [ ] Delete removes record

**Coverage:**
- [ ] Minimum 90% overall coverage
- [ ] 100% coverage on domain layer
- [ ] All edge cases tested
- [ ] All validation rules tested

---

## Best Practices

**DO:**
- Write tests BEFORE or WITH code (TDD/BDD)
- Test one thing per test method
- Use descriptive test names (`test_throws_exception_for_price_too_low`)
- Use factories for test data
- Mock external dependencies
- Test edge cases and boundaries
- Keep tests fast (unit < 10ms, integration < 100ms)

**DON'T:**
- Test framework code (CodeIgniter, PHP)
- Test getters/setters without logic
- Share state between tests
- Use production database for tests
- Skip tests for "simple" code
- Comment out failing tests

---

**Use `test-specialist` to ensure comprehensive test coverage.**
