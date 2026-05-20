# Testing Guide - CodeIgniter 4 CQRS Template

## Overview

This project follows industry-standard testing practices with comprehensive test coverage of the Cookie domain.

**Test Statistics:**
- **Test Files**: 22 comprehensive test files
- **Test Cases**: 237+ assertions
- **Coverage Target**: 95%+ (100% of domain layer)
- **Test Speed**: <10 seconds for all unit tests

---

## Test Structure (Testing Pyramid)

```
tests/
├── Unit/ (70% of tests)          - Fast, isolated, no dependencies
│   └── Domain/Cookie/
│       ├── Entities/              - Business logic tests
│       ├── ValueObjects/          - Validation tests
│       ├── Commands/              - Command handler tests
│       ├── Queries/               - Query handler tests
│       └── Events/                - Event tests
│
├── Integration/ (20% of tests)   - Real database operations
│   └── Repositories/
│       └── CookieRepositoryTest.php
│
└── Feature/ (10% of tests)       - Full HTTP flows
    └── Cookie/
        └── CookieCrudTest.php
```

---

## Running Tests

### All Tests
```bash
composer test
```

### Specific Test File
```bash
vendor/bin/phpunit tests/Unit/Domain/Cookie/ValueObjects/CookieNameTest.php
```

### Specific Test Method
```bash
vendor/bin/phpunit --filter test_can_create_with_valid_name
```

### With Code Coverage
```bash
composer test:coverage
# Opens HTML report in build/logs/html/index.html
```

### Unit Tests Only (Fast)
```bash
vendor/bin/phpunit tests/Unit/
```

### Integration Tests Only
```bash
vendor/bin/phpunit tests/Integration/
```

### Feature Tests Only
```bash
vendor/bin/phpunit tests/Feature/
```

---

## Test Tools

### PHPUnit 12.x
- Latest version with PHP 8.3+ support
- Attribute-based configuration
- Better error messages
- Improved performance

### Test Base Classes
- `UnitTestCase` - Mock helpers, assertion helpers
- `IntegrationTestCase` - Database helpers, transactions
- `FeatureTestCase` - HTTP helpers, flash message assertions

### Factories
- `CookieFactory` - Test data builders for consistent test data

### Xdebug Integration
- Debug tests with VS Code
- Set breakpoints in tests or code
- Step through execution

---

## Writing Tests

### AAA Pattern (Arrange-Act-Assert)

Always follow this structure:

```php
public function test_description_of_what_is_being_tested(): void
{
    // Arrange - Set up test data and expectations
    $input = 'test value';
    $expected = 'expected result';

    // Act - Execute the code being tested
    $actual = MethodUnderTest::execute($input);

    // Assert - Verify the result
    $this->assertEquals($expected, $actual);
}
```

### Unit Test Example

```php
use Tests\Support\UnitTestCase;
use App\Domain\Cookie\ValueObjects\CookieName;

final class CookieNameTest extends UnitTestCase
{
    public function test_can_create_with_valid_name(): void
    {
        // Arrange & Act
        $name = CookieName::fromString('Chocolate Chip');

        // Assert
        $this->assertInstanceOf(CookieName::class, $name);
        $this->assertEquals('Chocolate Chip', $name->getValue());
    }

    public function test_throws_exception_for_empty_name(): void
    {
        // Arrange
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('required');

        // Act
        CookieName::fromString('');
    }
}
```

### Integration Test Example

```php
use Tests\Support\IntegrationTestCase;
use App\Infrastructure\Persistence\Repositories\CookieRepository;
use Tests\Support\Factories\CookieFactory;

final class CookieRepositoryTest extends IntegrationTestCase
{
    private CookieRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new CookieRepository();
    }

    public function test_can_save_and_retrieve_cookie(): void
    {
        // Arrange
        $cookie = CookieFactory::createCookie();

        // Act
        $cookieId = $this->repository->save($cookie);
        $retrieved = $this->repository->findById($cookieId);

        // Assert
        $this->assertNotNull($retrieved);
        $this->assertEquals($cookie->getName()->getValue(), $retrieved->getName()->getValue());
        $this->assertDatabaseHas('cookies', ['id' => $cookieId]);
    }
}
```

### Feature Test Example

```php
use Tests\Support\FeatureTestCase;
use Tests\Support\Factories\CookieFactory;

final class CookieCrudTest extends FeatureTestCase
{
    public function test_can_create_cookie_via_http(): void
    {
        // Arrange
        $formData = CookieFactory::createFormData([
            'name' => 'Test Cookie',
            'price' => '2.99',
        ]);

        // Act
        $result = $this->post('/cookies', $formData);

        // Assert
        $result->assertRedirect();
        $this->assertFlashMessage('success', 'created successfully');
        $this->assertDatabaseHas('cookies', ['name' => 'Test Cookie']);
    }
}
```

---

## Testing Patterns

### Data Providers (Parameterized Tests)

Test multiple scenarios with one test method:

```php
/**
 * @dataProvider invalidNameProvider
 */
public function test_throws_exception_for_invalid_names(
    string $invalidName,
    string $expectedError
): void {
    $this->expectException(ValidationException::class);
    $this->expectExceptionMessage($expectedError);

    CookieName::fromString($invalidName);
}

public static function invalidNameProvider(): array
{
    return [
        'empty string' => ['', 'required'],
        'too short' => ['AB', 'at least 3 characters'],
        'too long' => [str_repeat('A', 101), 'cannot exceed 100 characters'],
    ];
}
```

### Mocking Dependencies

```php
use PHPUnit\Framework\MockObject\MockObject;

public function test_handler_calls_repository(): void
{
    // Arrange
    /** @var CookieRepository&MockObject */
    $repository = $this->createMock(CookieRepository::class);
    $repository->expects($this->once())
        ->method('save')
        ->willReturn(1);

    $handler = new CreateCookieHandler($repository, $eventDispatcher);

    // Act
    $result = $handler->handle($command);

    // Assert
    $this->assertEquals(1, $result);
}
```

### Test Factories

```php
// Create with defaults
$cookie = CookieFactory::createCookie();

// Override specific values
$cookie = CookieFactory::createCookie([
    'name' => 'Custom Name',
    'price' => 5.99,
]);

// Create multiple
$cookies = CookieFactory::createMultiple(5);

// Create invalid data for validation tests
$invalidData = CookieFactory::createInvalidFormData('name_too_short');
```

---

## Test Coverage

### View Coverage Report

```bash
composer test:coverage
# Opens: build/logs/html/index.html
```

### Coverage Targets

| Layer | Target Coverage |
|-------|----------------|
| Domain Layer | 100% |
| Repositories | 100% |
| Controllers | 95% |
| Overall | 95%+ |

### What to Test

**DO Test:**
- ✅ Business rules and validation
- ✅ Value object behavior
- ✅ Entity state changes
- ✅ Command/Query handler logic
- ✅ Repository database operations
- ✅ HTTP request/response flows
- ✅ Error handling and exceptions
- ✅ Edge cases and boundary conditions

**DON'T Test:**
- ❌ Framework code (CodeIgniter internals)
- ❌ Third-party libraries
- ❌ Simple getters/setters (unless they have logic)
- ❌ Private methods (test through public API)

---

## Debugging Tests with Xdebug

### 1. Set Breakpoint in Test
```php
public function test_something(): void
{
    $value = 'test';  // Set breakpoint here
    $result = MethodUnderTest::execute($value);
    $this->assertEquals('expected', $result);
}
```

### 2. Run Debug Configuration
- Press **F5** in VS Code
- Select "Debug Current PHPUnit Test"
- Execution pauses at breakpoint

### 3. Inspect Variables
- Hover over variables
- Check Variables panel
- Use Debug Console to evaluate expressions

---

## Best Practices

### Test Naming

```php
// ✅ Good - Describes what is being tested
test_can_create_with_valid_name()
test_throws_exception_when_name_too_short()
test_redirects_to_index_after_successful_creation()

// ❌ Bad - Unclear what is being tested
test_name()
test_validation()
test_controller()
```

### Assertion Messages

```php
// ✅ Good - Helpful message
$this->assertEquals(
    expected: 'Chocolate Chip',
    actual: $cookie->getName()->getValue(),
    message: sprintf(
        'Expected cookie name to be "Chocolate Chip" but got "%s"',
        $cookie->getName()->getValue()
    )
);

// ❌ Bad - No context on failure
$this->assertEquals('Chocolate Chip', $cookie->getName()->getValue());
```

### Test Independence

```php
// ✅ Good - Each test is independent
public function test_create_cookie(): void
{
    $cookie = CookieFactory::createCookie();  // Fresh data each test
    // ... test
}

// ❌ Bad - Tests depend on shared state
private $sharedCookie;  // Avoid shared state

public function test_one(): void
{
    $this->sharedCookie = CookieFactory::createCookie();
}

public function test_two(): void
{
    // Depends on test_one running first!
    $name = $this->sharedCookie->getName();
}
```

### Test Organization

```php
final class CookieTest extends UnitTestCase
{
    // ==================== SUCCESSFUL CREATION TESTS ====================

    public function test_can_create_with_valid_data(): void { }
    public function test_can_create_with_minimum_values(): void { }

    // ==================== VALIDATION ERROR TESTS ====================

    public function test_throws_exception_for_invalid_price(): void { }
    public function test_throws_exception_for_negative_stock(): void { }

    // ==================== BUSINESS RULE TESTS ====================

    public function test_cannot_decrease_stock_below_zero(): void { }
    public function test_inactive_cookies_are_hidden(): void { }
}
```

---

## CI/CD Integration

### GitHub Actions Example

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: pcov

      - name: Install Dependencies
        run: composer install

      - name: Run PHPStan
        run: composer phpstan

      - name: Run PHPCS
        run: composer phpcs

      - name: Run Tests with Coverage
        run: composer test:coverage

      - name: Upload Coverage
        uses: codecov/codecov-action@v3
```

---

## Troubleshooting

### Tests Run Slow
```bash
# Disable Xdebug for faster tests
php -dxdebug.mode=off vendor/bin/phpunit

# Or run unit tests only
vendor/bin/phpunit tests/Unit/
```

### Database Connection Errors
```php
// Check .env database configuration
database.tests.hostname = localhost
database.tests.database = ci4_cqrs_test
database.tests.username = root
database.tests.password = root
```

### Mock Expectations Not Met
```php
// Verify mock setup
$mock->expects($this->once())  // Called exactly once
$mock->expects($this->atLeastOnce())  // Called one or more times
$mock->expects($this->never())  // Never called
```

---

## Next Steps

1. ✅ Created comprehensive test suite (237+ tests)
2. ✅ Configured Xdebug for debugging
3. ✅ Upgraded to PHPUnit 12.x
4. Run tests: `composer test`
5. View coverage: `composer test:coverage`
6. Debug failing tests with Xdebug (F5 in VS Code)

---

## Resources

- [PHPUnit Documentation](https://docs.phpunit.de/)
- [Testing Best Practices](https://docs.phpunit.de/en/12.5/writing-tests-for-phpunit.html)
- [Xdebug Setup Guide](./XDEBUG_SETUP.md)
- [Cookie Domain Tests](./tests/Unit/Domain/Cookie/)

---

**Happy Testing! 🧪**
