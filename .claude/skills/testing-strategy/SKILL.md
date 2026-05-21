---
name: testing-strategy
description: Test pyramid (70/20/10) for this CQRS template, 90% coverage gate, test locations and naming conventions. Load when writing or modifying tests.
allowed-tools: [Read, Write, Edit, Glob, Grep, Bash]
---

# Testing Strategy

This template enforces a strict test pyramid through the `test-specialist`
agent. The gate fails any change that drops coverage below **90 %** or
inverts the pyramid balance.

---

## The pyramid

| Layer | Share | Where it goes | What it tests |
|---|---|---|---|
| **Unit** | 70 % | `tests/Unit/Domain/{Domain}/` | Handlers, value objects, entities, events — in isolation, with mocks for ports |
| **Integration** | 20 % | `tests/Integration/Repositories/` | Repositories against a real database |
| **Feature** | 10 % | `tests/Feature/{Domain}/` | End-to-end HTTP flow through controllers |

---

## Minimum coverage: 90 %

```bash
# Plain
composer test

# With coverage report
vendor/bin/phpunit --coverage-text

# Readable test names
vendor/bin/phpunit --testdox

# Single file or filter
vendor/bin/phpunit --filter CreateCookieHandlerTest
```

---

## Test locations and naming

```
tests/
├── Unit/
│   └── Domain/{Domain}/
│       ├── ValueObjects/{VO}Test.php
│       ├── Entities/{Entity}Test.php
│       ├── Commands/{Command}HandlerTest.php
│       ├── Queries/{Query}HandlerTest.php
│       └── Events/{Event}EventHandlerTest.php
├── Integration/
│   └── Repositories/{Entity}RepositoryTest.php
└── Feature/
    └── {Domain}/{Domain}ControllerTest.php
```

Each test class ends in `Test`. Each test method starts with `test` (or
uses the `#[Test]` attribute) and reads as a sentence:

```php
public function testCreatesCookieWithValidName(): void
{ /* ... */ }

public function testRejectsNegativeStock(): void
{ /* ... */ }
```

---

## Unit-test conventions

- Mock the **port** (`UserRepositoryInterface`), never the **adapter**
  (`UserRepository`). The handler is supposed to depend on the port.
- For `LoggerInterface`, the project ships a `tests/Support/Doubles/InMemoryLogger.php`
  (or similar) — prefer it over `createMock(LoggerInterface::class)` when you
  want to assert on log lines.
- For value objects, prefer **table-driven tests** for the happy + sad paths:

  ```php
  public static function invalidNameProvider(): array
  {
      return [
          'empty'   => ['', 'User name is required'],
          'too short' => ['A', 'must be at least 2'],
          'too long'  => [str_repeat('x', 101), 'must not exceed 100'],
      ];
  }

  #[DataProvider('invalidNameProvider')]
  public function testRejectsInvalidName(string $input, string $expectedMessage): void
  { /* ... */ }
  ```

---

## Integration-test conventions

- Use the `CIUnitTestCase` with `DatabaseTestTrait`.
- One repository per test class.
- Migrate fresh per test method (`refresh = true`); the test fixture is
  deterministic.
- Assert on the row count and the returned entity, not on raw SQL.

---

## Feature-test conventions

- Use `FeatureTestTrait`.
- Hit a route via `$this->call('post', '/cookies', $payload)` and assert on
  the JSON response, the HTTP status, and the database side-effect.
- Don't test what the unit tests already cover — feature tests only verify
  the wiring.

---

## What the gate rejects automatically

- Total coverage below 90 %.
- A new public method on a handler / VO / entity without a test.
- An integration test for a class whose unit test was deleted in the same change.
- A skipped test without an `@todo` referencing an issue number.

The `test-specialist` agent runs after every code change. If you delete a
test you must explain why in the commit message.
