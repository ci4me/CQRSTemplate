# 12 — Cookie Unit Tests

**Slice:** tests/Unit/Domain/Cookie/** + CookieFactory
**Reviewer:** test-specialist
**Date:** 2026-05-22
**Source files reviewed:** 15 test files (15 listed in scope, all confirmed present) + 1 factory + supporting `UnitTestCase`. Cross-referenced against 13 Cookie production classes.

## TL;DR

The Cookie unit suite is dense (155 test methods across 15 classes) and exemplary in many places: real value objects are constructed rather than mocked, the `[AllowMockObjectsWithoutExpectations]` attribute is used responsibly, query-handler logging modes are explored at the level of branch coverage, and the entity test asserts event-buffer behaviour (`pullEvents`, `hasPendingEvents`). However the suite has three template-level defects that will be cloned verbatim into every future domain: (1) it silently writes to the real filesystem from 19 different "unit" tests via `LoggerFactory::create()`, which opens a `RotatingFileHandler` on `writable/logs/app.json` — that is a filesystem dependency the CLAUDE.md spec forbids in unit tests; (2) there is no `CookieStockTest`, no `PriceFormatterTest`, no `CookieAccessorsTest`, and no `ErrorCodes` smoke test, so four reviewable production files have zero direct unit coverage — `CookieStock` is a brand-new Phase-4 split with branch logic (decrement-below-zero, non-positive quantity) that is only tested transitively through `Cookie`; (3) the event-handler tests are largely no-op smoke tests (`$handler($event); $this->assertTrue(true);`) that prove only "no exception was thrown" rather than asserting log-context shape, and the immutability tests on event DTOs (`readonly`) are tautologies. Naming is inconsistent (snake-case `test_*` everywhere except inline comments mixing camelCase descriptors), and a handful of tests use `\Exception::class` as the catch-all instead of the precise `ValidationException`/`DomainException` they actually expect.

## Verdict
READY-WITH-FIXES

## Findings

### F1 — HIGH — Unit tests open real log files via `LoggerFactory::create()`
- **Location:** 19 call sites across `Commands/CreateCookieHandlerTest.php:35`, `UpdateCookieHandlerTest.php:31`, `DeleteCookieHandlerTest.php:31`, `RestoreCookieHandlerTest.php:33`, `Events/CookieEventHandlersTest.php:31,39,56,72,92,100,115,131,149,157,171,185,224`, `CookieServiceProviderTest.php:73`. Confirmed: `app/Infrastructure/Logging/LoggerFactory.php:46-65` always pushes a `RotatingFileHandler` writing to `writable/logs/app.json`. The `writable/logs/` directory already contains seven months of `app-*.json` files including ones with names matching test-run dates — strong evidence the suite writes there.
- **Observation:** The five command/event tests instantiate the production `LoggerFactory` instead of `$this->createMock(LoggerInterface::class)`. Every test run that lives in `tests/Unit/` therefore touches the filesystem, mutates daily log files, and races on writes if PHPUnit runs in parallel. The handler tests use a real logger AND `$this->assertTrue(true)` as their only assertion — the test is structurally "does nothing throw when we log to disk?", which is filesystem-dependent, not a unit test. CLAUDE.md's `testing-strategy` skill explicitly states unit tests must have "no DB, no FS, no network."
- **Why this is a template defect:** Cloning this pattern is the path of least resistance. `OrderHandlerTest`, `InvoiceHandlerTest`, etc. will all open the same shared `app.json` file from the unit suite, producing untraceable log noise during `composer test`, and breaking on read-only filesystems (CI containers, locked-down runners). The query-handler tests prove it can be done right with `$this->createMock(LoggerInterface::class)` (see `GetCookieByIdHandlerTest.php:27`); the command tests do not follow that pattern.
- **Suggested fix:** Replace all `LoggerFactory::create('test.…')` calls in `tests/Unit/` with `$this->createMock(LoggerInterface::class)` or a `NullLogger` (Psr\Log). For the cases where you DO want to assert log structure (e.g. `CookieRestoredEventHandler`, `CookieStockChangedEventHandler`), the test already shows the right pattern — extend it to the four "logs without error" smoke tests. Better: forbid `LoggerFactory` import from `tests/Unit/` via deptrac.

### F2 — HIGH — Missing tests for `CookieStock`, `PriceFormatter`, `CookieAccessors`, `ErrorCodes`
- **Location:** `app/Domain/Cookie/ValueObjects/CookieStock.php` (92 lines, 6 branches: fromInt-negative, decrementBy-positive-quantity, decrementBy-zero-quantity, decrementBy-below-zero, incrementBy-non-positive, isOutOfStock); `app/Domain/Cookie/Services/PriceFormatter.php` (null vs explicit currency-symbol arms); `app/Domain/Cookie/Entities/CookieAccessors.php` (9 getters, trait); `app/Domain/Cookie/ErrorCodes.php` (16 constants, no test asserts cross-domain collision documentation). No `CookieStockTest.php`, `PriceFormatterTest.php`, `CookieAccessorsTest.php`, or `ErrorCodesTest.php` exists — confirmed via `find tests/Unit/Domain/Cookie -name "*Test.php"`.
- **Observation:** `CookieStock` is a Phase-4 split that the entity now delegates to (`Cookie.php:85,105,165,234,246`). Every CookieStock branch is exercised transitively through `CookieTest`, but the value object itself has no dedicated coverage. The docblock on `CookieStock` even invites the test ("AI agents and humans can reason about [it] in isolation") — the test that proves that promise is missing. `PriceFormatter::format(?string $currencySymbol = null)` has a null-vs-string-arm distinction (`PriceFormatter.php:34-37`) that is only covered if `CookiePriceTest::test_format_with_explicit_symbol_overrides_default` happens to hit it via the `CookiePrice::format()` proxy — but `CookiePrice::format()` does not call `PriceFormatter` at all (it calls `$this->money->format()` directly), so `PriceFormatter` is dead-tested.
- **Why this is a template defect:** A developer cloning to `Foo` will copy the `Cookie::FooStock` VO + handler pattern but will not even know `FooPriceFormatter` and `FooStock` are supposed to have their own tests, because the reference template doesn't. The 70-% unit-test pyramid floor depends on every VO having its own file.
- **Suggested fix:** Add `tests/Unit/Domain/Cookie/ValueObjects/CookieStockTest.php` covering: `fromInt(-1)` throws `ValidationException` with `ErrorCodes::COOKIE_VALIDATION_STOCK`; `decrementBy(0)` and `decrementBy(-1)` throw with code 1 (note the missing-error-code bug at `CookieStock.php:89`); `decrementBy(>value)` throws `DomainException` with `COOKIE_BUSINESS_RULE_STOCK_NEGATIVE`; immutability (decrementBy returns a new instance). Add `PriceFormatterTest.php` for the null/non-null branches. Add an `ErrorCodesTest` that asserts every constant is `int` and unique within the class (smoke test against future copy/paste typos).

### F3 — HIGH — Event-handler "smoke tests" assert only `assertTrue(true)`
- **Location:** `CookieEventHandlersTest.php:51, 67, 83, 110, 125, 140, 166, 180, 195, 233, 275` — 11 of 16 test methods end with `$this->assertTrue(true);` after invoking the handler.
- **Observation:** These tests prove the handler is callable and doesn't throw when given a real `LoggerFactory` logger. They do not verify the log message, the log level, or the context shape. The two tests that DO assert correctly — `test_cookie_restored_handler_logs_with_audit_context` and `test_cookie_stock_changed_handler_logs_movement_context` — show the right pattern: mock `LoggerInterface`, call `$logger->expects($this->once())->method('info')->with('Cookie restored', $this->callback(…))`. The other 11 tests should follow that pattern.
- **Why this is a template defect:** The created/updated/deleted handler tests will be copy-pasted for every new domain. Each clone will assert nothing about its handler's log contract — so a regression that logs `Cookie deleted` at `warning` instead of `info`, or drops the `cookie_id` from the context, will pass green. PHPUnit's `failOnRisky="true"` (set in `phpunit.xml.dist`) won't catch these because one `assertTrue(true)` is enough to satisfy the risky-detector.
- **Suggested fix:** Convert the 11 smoke tests to use `$this->createMock(LoggerInterface::class)` and assert the message + context shape, exactly as `test_cookie_restored_handler_logs_with_audit_context` already does. As a side-effect this also resolves F1 for these tests.

### F4 — MEDIUM — `expectException(\Exception::class)` and `expectException(\RuntimeException::class)` lose type specificity
- **Location:** `CreateCookieHandlerTest.php:109, 125, 141` use `$this->expectException(\Exception::class)` — the broadest possible base class; `CreateCookieHandlerTest.php:261`, `UpdateCookieHandlerTest.php:166`, `RestoreCookieHandlerTest.php:79` use `\RuntimeException::class` for repository failures without verifying the wrapped error code from the handler's `determineErrorCode()`.
- **Observation:** `expectException(\Exception::class)` will pass on any exception type whatsoever, including `ParseError` from a typo. The asserts at lines 109/125/141 are testing value-object validation thrown from inside the handler — these should be `ValidationException::class` (which `test_rethrows_validation_exception_from_value_object` at line 240 correctly does). The mixed style (sometimes `\Exception::class`, sometimes `ValidationException::class`) inside the same test class is the worst of both worlds. Same on the `RuntimeException` cases: the handler at `CreateCookieHandler.php:164` wraps via `determineErrorCode()` and re-throws with `COOKIE_REPOSITORY_SAVE_FAILED`; the test verifies the message survives but not the error code.
- **Why this is a template defect:** Cloned tests will assert `\Exception::class` for everything — losing the entire benefit of the typed `ValidationException`/`DomainException` hierarchy that the project documentation goes to lengths to define. ErrorCodes (`F2`) become unverifiable.
- **Suggested fix:** Replace all `\Exception::class` with the precise type (`ValidationException`, `DomainException`). For `\RuntimeException` flows, additionally call `$this->expectExceptionCode(ErrorCodes::COOKIE_REPOSITORY_SAVE_FAILED)` so a future refactor of `determineErrorCode()` is detectable.

### F5 — MEDIUM — `CookieEventsTest` immutability tests are tautologies
- **Location:** `CookieEventsTest.php:45-58, 112-123, 172-182` — three "is_immutable" tests that simply assign `$event->cookieId` to `$this->assertSame()` and call it a proof of immutability.
- **Observation:** Readonly enforcement is a PHP language feature; you cannot test it by reading a value. The test would need `$event->cookieId = 99;` inside a `try/catch \Error` to genuinely exercise the readonly contract — at which point a static analyzer (PHPStan L8) already prevents the assignment from being written. As composed, these tests are noise; they inflate the assertion count without proving anything new beyond what `test_…_stores_all_properties` already proves.
- **Why this is a template defect:** Three identical no-op tests per event class × N future domains × ~5 events each = a lot of dead code propagated. The cloner will think these tests are load-bearing.
- **Suggested fix:** Delete the three `_is_immutable` tests, or replace them with a single shared test that uses reflection to assert each event class is `final readonly` and every property is also `readonly`. The latter scales to N events with one method.

### F6 — MEDIUM — `CookieDeletedEvent` carries no `deletedBy` / `deletedAt` payload (test reflects production gap)
- **Location:** `CookieEventsTest.php:151-208`, `CookieEventHandlersTest.php:147-196`. Confirmed against `app/Domain/Cookie/Events/CookieDeleted/CookieDeletedEvent.php` (cookieId + cookieName only).
- **Observation:** Compare to `CookieRestoredEvent` which carries `restoredBy: int, restoredAt: string` (`CookieRestoredEvent.php:18-21`) and whose handler test verifies all three fields. `CookieDeletedEvent` only carries `cookieId` and `cookieName` — no actor, no timestamp — and the test never flags the gap. Cloners will assume "deleted" doesn't need audit context, but "restored" does.
- **Why this is a template defect:** Inconsistent event payload conventions across the reference domain create a coin-flip for the next developer. The test suite codifies the inconsistency rather than flagging it.
- **Suggested fix:** Out of scope for the unit-test slice (production fix), but the test should at least add a TODO comment or a `@todo` data assertion documenting that the deleted event is missing audit context relative to its sibling.

### F7 — LOW — `CookieFactory::createDatabaseRow` and `createFormData` are dead code in unit tests
- **Location:** `tests/Support/Factories/CookieFactory.php:87-102, 131-163`.
- **Observation:** `grep -rn 'createDatabaseRow\|createFormData\|createInvalidFormData' tests/Unit/` returns zero hits in the unit slice. These factory methods exist for integration/feature tests but live in `tests/Support/Factories/`. The `priceFromMixed` fallback `return CookiePrice::fromString('');` (line 175) will throw `ValidationException` if invoked with a non-string/non-numeric value — silent type laundering.
- **Why this is a template defect:** A cloner reading `CookieFactory` will assume all six methods are part of the unit-test contract. The `priceFromMixed('')` branch is a runtime trap when called with `null`.
- **Suggested fix:** Either (a) move `createDatabaseRow`/`createFormData`/`createInvalidFormData` to a separate `CookieFormDataBuilder` only autoloaded for integration/feature tests, or (b) write a unit test for `CookieFactory` itself that asserts each method's contract — especially the `priceFromMixed` fallback. Option (b) is more honest because it documents what's intentional.

### F8 — LOW — `CreateCookieHandlerTest::test_determine_error_code_match_arms_for_zero_coded_domain_exceptions` is testing private behaviour through a string-discriminator side channel
- **Location:** `CreateCookieHandlerTest.php:275-311`.
- **Observation:** The data-provider's five rows each construct a `DomainException` whose **message text** is engineered to hit a specific `str_contains()` arm of the handler's private `determineErrorCode()` method. The test asserts the exception re-emerges with the same message, but never asserts the resulting error code — defeating its stated purpose. As written, the test gives coverage of the match arms (which inflates line coverage) without verifying they map to the correct ErrorCode constant.
- **Why this is a template defect:** Cloners will copy the pattern. Coverage will look healthy; the actual contract (error-code mapping) is untested. Worse, `determineErrorCode()` itself is brittle (substring matching on exception messages is a known anti-pattern); the test cements it.
- **Suggested fix:** Assert the error code on the re-thrown exception via `$this->expectExceptionCode(ErrorCodes::COOKIE_BUSINESS_RULE_NAME_DUPLICATE)` per data row. Once that's done, the pattern self-documents and the next person to refactor `determineErrorCode()` gets a real signal.

### F9 — LOW — `UnitTestCase::assertExceptionMessage` catches `\Exception`, not `\Throwable`
- **Location:** `tests/Support/UnitTestCase.php:33`.
- **Observation:** PHP 8.3+ `\Error` subtypes (`TypeError`, `ValueError`, `AssertionError`) inherit from `\Throwable` but not `\Exception`. A test using this helper to verify a `TypeError` slips through. Noted in `.audit/round2/r07-testing.md` already — verifying still present.
- **Why this is a template defect:** The helper is in the shared base class — every clone inherits the wrong catch.
- **Suggested fix:** Change `catch (\Exception $e)` to `catch (\Throwable $e)` in `UnitTestCase::assertExceptionMessage`. Two-character change.

### F10 — LOW — Whitespace bug in entity test `version: 1` indentation (cosmetic but signals copy-paste)
- **Location:** `CookieTest.php:97-98, 119-120, 386-387, 404-405` (and matching pattern in `RestoreCookieHandlerTest.php:96-97, 112-113`).
- **Observation:** The `version: 1` named argument is indented one less level than its peers — clear evidence the parameter was added by a search-and-replace that didn't re-format. Same micro-pattern in two test files signals that whoever added optimistic-locking did so via grep-replace, not via Serena/LSP. This is cosmetic but if PHPCS isn't catching it the linter rules are too loose.
- **Why this is a template defect:** Clones will inherit the indentation drift.
- **Suggested fix:** Run `vendor/bin/phpcbf tests/Unit/Domain/Cookie/`. If it doesn't fix the indentation, tighten the Slevomat alignment rule.

### F11 — INFO — Test naming is consistent (`test_…` snake_case) — keep it
- **Location:** All 155 methods across 15 files.
- **Observation:** Zero `testFooBar()` camelCase, zero `it_…` BDD-style. Snake-case `test_does_X_when_Y` is uniform. This is praiseworthy and the strict convention should be documented in `testing-strategy` skill if it isn't already.
- **Why this is a template defect:** Not a defect — flagging because it's a load-bearing convention that should be preserved as the template scales.
- **Suggested fix:** Add an explicit rule to `.claude/skills/testing-strategy/SKILL.md` and consider a tiny PHPStan rule (or a `composer check` script) that fails on non-snake-case test names.

### F12 — INFO — `CookieFactory::createPersistedCookie` accepts a `version` override but the default isn't documented
- **Location:** `tests/Support/Factories/CookieFactory.php:51-79`. The defaults array doesn't include `version`, but `reconstitute()` requires it (`Cookie.php:103`); the factory always passes `version: 1` hard-coded (line 77).
- **Observation:** `UpdateCookieHandlerTest::test_expected_version_mismatch_aborts_before_value_object_parse` (line 133) passes `'version' => 1` in the overrides, but the factory's `array_merge($defaults, $overrides)` ignores it because `version` isn't a defaulted key and the merged value is never read — the factory just hard-codes `version: 1` regardless. So the test reads as "factory version is 1" but actually proves "any value of version in overrides is silently dropped." If the test author had written `'version' => 99`, the test would still see version 1.
- **Why this is a template defect:** A silent override drop is a footgun. Cloners writing version-aware tests (Order has revision-tracking, Invoice has audit numbers) will trip on it.
- **Suggested fix:** Wire `'version' => 1` into the defaults array and pass `$data['version']` to `reconstitute()`. Or — cleaner — make `version` a typed named argument on the factory method instead of a magic array key.

## Missing tests

Direct (file-level) coverage gaps:

1. **`CookieStock`** (`app/Domain/Cookie/ValueObjects/CookieStock.php`) — no `CookieStockTest.php`. Public surface: `fromInt`, `decrementBy`, `incrementBy`, `isOutOfStock`, `value` property. ~6 branches uncovered directly.
2. **`PriceFormatter`** (`app/Domain/Cookie/Services/PriceFormatter.php`) — no `PriceFormatterTest.php`. Public surface: `format(CookiePrice, ?string)`. 2 branches uncovered.
3. **`CookieAccessors`** (trait, `app/Domain/Cookie/Entities/CookieAccessors.php`) — no `CookieAccessorsTest.php`. 9 getters; covered transitively through `CookieTest` but no direct trait test asserts the trait contract on a different consumer.
4. **`ErrorCodes`** (`app/Domain/Cookie/ErrorCodes.php`) — no test. 16 constants; should at minimum assert no duplicate values and that the documented ranges (100-199 validation, etc.) are respected.
5. **`Cookie::getVersion` and `Cookie::bumpVersion`** — no direct test. `bumpVersion` only fires from the repository (production) and is never exercised in the unit layer; `getVersion` is read indirectly by `UpdateCookieHandlerTest` but never asserted as its own contract.
6. **`Cookie::activate` happy-path event emission** — the entity test asserts `activate()` flips `isActive`, but neither `activate()` nor `deactivate()` raise events (currently), so no test documents this design choice. If a future developer adds `CookieActivatedEvent` this gap will not be caught.
7. **`Cookie::update` happy-path event emission** — `CookieUpdatedEvent` is raised inside `Cookie::update()` (`Cookie.php:172-178`) but `CookieTest::test_can_update_all_fields` (line 128) never asserts `pullEvents()`. The fact that update emits an event is only proven indirectly through `UpdateCookieHandlerTest::test_updates_cookie_successfully` (which mocks the dispatcher). The entity-level event raising is uncovered.
8. **`CookieFactory` itself** — no `CookieFactoryTest.php`. The silent-drop bug in F12 and the `priceFromMixed` fallback in F7 are uncovered.
9. **`CookieRestoredEvent` / `CookieStockChangedEvent` payload immutability** — `CookieEventsTest` covers Created/Updated/Deleted only, missing the two events tested in `CookieEventHandlersTest`.
10. **`CookieServiceProvider::registerCommands` success path** — only the "wrong type" rejection (line 24) is tested; the happy path that registers handlers on the bus is not unit-tested at all.

## What is correct / praiseworthy

- **No mocked value objects.** Every test constructs real `CookieName`, `CookiePrice` instances; mocking is reserved for `Repository`, `EventDispatcher`, `LoggerInterface`, `LogConfigPort`. This is the right policy and matches the CLAUDE.md "don't mock the database" spirit applied correctly.
- **Query-handler logging-mode coverage is excellent.** `GetCookieByIdHandlerTest`, `GetAllCookiesHandlerTest`, `GetCookiesPaginatedHandlerTest` each cover all five logging-config branches (errors / all / slow / sampling@0 / sampling@1 / unknown-default). The `stubConfig()` helper makes this readable.
- **Event-buffer assertions on the aggregate are present.** `CookieTest::test_decrease_stock_raises_event_on_aggregate` (line 261) and `…test_increase_stock_raises_event_on_aggregate` (line 286) explicitly assert the entity buffers, drains, and clears events — exactly what aggregate tests should look like.
- **Data providers are used at boundary points.** `CookieNameTest::invalidNameProvider`, `CookiePriceTest::invalidPriceProvider`, and `CreateCookieHandlerTest::domainExceptionMessageProvider` show the right shape; they need wider adoption (see F8) but the pattern is established.
- **Optimistic-locking abort short-circuits before VO parse.** `UpdateCookieHandlerTest::test_expected_version_mismatch_aborts_before_value_object_parse` (line 117) — explicitly asserts `expects($this->never())` on the uniqueness check. This is the right way to write a "fails fast" test.
- **`CookieServiceProviderTest` covers the bouncer guard.** Pinning the `instanceof` rejection (lines 24-54) prevents a future refactor from silently weakening DI safety.
- **`AAA` structure is present** in `CookieNameTest` with explicit `// Arrange / // Act / // Assert` comments. Other files are less explicit but the structure is recognisable.
- **No `sleep()` calls** in the unit suite. (`sleep(1)` lives in the integration layer only — that's the right place for it.)
- **Final classes everywhere; `[AllowMockObjectsWithoutExpectations]` is annotated.** Both signal a deliberate testing posture.

## Top 3 fixes before cloning

1. **F1: Replace `LoggerFactory::create()` with `LoggerInterface` mocks across 19 unit-test call sites.** Real-FS logging from "unit" tests is a category violation and will be inherited by every cloned domain.
2. **F2: Add `CookieStockTest`, `PriceFormatterTest`, `CookieAccessorsTest`, `ErrorCodesTest`.** Four production files currently have no direct unit coverage; the reference domain must demonstrate the full pyramid before any other domain copies it.
3. **F3 + F4: Stop using `assertTrue(true)` and `\Exception::class` as placeholder assertions.** Convert the 11 event-handler smoke tests to assert log message + context shape (the pattern is already present in `CookieRestoredEventHandler` test), and replace `\Exception::class` with `ValidationException::class` / `DomainException::class` + `expectExceptionCode(ErrorCodes::…)`. This locks the ErrorCode contract into the test surface.

---

**Severity counts:** CRITICAL 0 | HIGH 3 | MEDIUM 3 | LOW 4 | INFO 2
**Top finding:** Unit tests open a real `RotatingFileHandler` on `writable/logs/app.json` via `LoggerFactory::create()` in 19 places — silent filesystem dependency that violates the unit-test isolation rule and will propagate to every cloned domain.
