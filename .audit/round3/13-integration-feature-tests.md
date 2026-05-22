# 13 — Integration & Feature Tests

**Slice:** Cookie integration + feature/E2E tests
**Reviewer:** test-specialist
**Date:** 2026-05-22
**Source files reviewed:** 7
(`tests/Feature/Cookie/CookieCrudTest.php`,
 `tests/Feature/Cookie/CookieQueryE2ETest.php`,
 `tests/Integration/Repositories/CookieRepositoryTest.php`,
 `tests/Integration/Repositories/CookieQueryRepositoryTest.php`,
 `tests/Integration/Repositories/CookieOptimisticLockingTest.php`,
 `tests/Support/IntegrationTestCase.php`,
 `tests/Support/FeatureTestCase.php`,
 plus `phpunit.xml.dist` and `tests/Support/Factories/CookieFactory.php` as dependencies.)

## TL;DR

The integration tests follow the "real DB" rule for happy paths but the
backing engine is **`:memory:` SQLite hard-locked by `phpunit.xml.dist`**
(`force="true"` on `database.tests.DBDriver`, line 67). Every concurrency,
collation, optimistic-lock, composite-UNIQUE and `SELECT ... FOR UPDATE`
claim in the Cookie reference is therefore validated against the wrong
engine. The optimistic-locking suite passes only because SQLite serialises
writes (the two "concurrent readers" in `CookieOptimisticLockingTest` run on
the same connection in the same process). Feature tests assert on the view
path string `cookies/index` rather than rendered content — the trick is now
ALSO reproduced one slice over (`CookieCrudTest`) AND fixed one slice over
(`CookieQueryE2ETest`) which means the template ships BOTH the right and the
wrong pattern side-by-side and a `sed s/Cookie/Foo/g` clone will copy
whichever one happens to be at the top of the file. The composite UNIQUE
`(tenant_id, name, deleted_at)` is never exercised (NULL-vs-NULL case
silently allows duplicates on MySQL too — the test that would catch this
doesn't exist). Repository tests freely mix `createMock(CookieModel)` for
error-path coverage with real-DB usage in the same class — the file calls
itself an integration test but eight methods are unit tests by construction.
A clone will inherit a hybrid posture without realising it.

## Verdict
**NOT-READY**

The suite cannot detect any of the CRITICAL concurrency or constraint
defects identified in round-2 reports r06 and r07. As a reference for new
domains it actively encourages the SQLite-only, content-blind feature-test
posture that those reports already called out.

## Findings

### F1 — CRITICAL — Integration suite is structurally SQLite-only; optimistic-locking tests cannot fail on the prod engine
- **Location:** `phpunit.xml.dist:62-69`; entire
  `tests/Integration/Repositories/CookieOptimisticLockingTest.php`;
  `tests/Integration/Repositories/CookieRepositoryTest.php:135-155`.
- **Observation:** `phpunit.xml.dist:67` is
  `<env name="database.tests.DBDriver" value="SQLite3" force="true"/>` —
  the `force="true"` overrides any local `.env` or CI env. SQLite is a
  single-writer engine; `CookieOptimisticLockingTest::test_concurrent_update_throws_domain_exception`
  pretends to model two writers by calling `findById($id)` twice on the same
  PHP connection and writing them serially. On MySQL InnoDB at REPEATABLE
  READ that's a different visibility model. More importantly,
  `affectedRows()` on MySQL counts rows-CHANGED (not rows-MATCHED) unless
  `CLIENT_FOUND_ROWS` is set (CI4 does not). The optimistic-lock gate in
  `CookieRepository::updateWithOptimisticLock` is `affectedRows() === 1`,
  so an idempotent re-save where the in-memory payload equals the DB row
  returns 0 on MySQL → false-positive `concurrentModification`. There is no
  test that re-saves the same payload twice; `test_save_updates_only_changed_fields`
  always renames so MySQL still sees a diff.
- **Why this is a template defect:** Every cloned domain inherits the
  same `phpunit.xml.dist` constraint, the same shape of optimistic-lock
  test, and the same false sense of security. The hour `s/Cookie/Invoice/g`
  the clone is "tested," every invoice save against MySQL with an idempotent
  retry will throw `DomainException::concurrentModification`.
- **Suggested fix:** Drop `force="true"` from `database.tests.DBDriver`
  (line 67) so CI can switch via env; add a second CI lane that runs the
  same suite against MySQL 8.0; add an explicit
  `test_save_idempotent_repeat_does_not_throw_concurrent_modification`
  regression. Optionally enable `CLIENT_FOUND_ROWS` in
  `app/Config/Database.php` for the MySQLi driver so the existing code is
  cross-engine-safe.

### F2 — CRITICAL — File named `CookieRepositoryTest` mixes real-DB cases with `createMock(CookieModel)` cases (it is half integration, half unit)
- **Location:** `tests/Integration/Repositories/CookieRepositoryTest.php:634-804`
  (eight `createMock(CookieModel::class)` methods inside an
  `IntegrationTestCase`-extending class).
- **Observation:** Methods `test_save_translates_duplicate_key_database_exception_into_domain_exception`,
  `test_save_rethrows_non_duplicate_database_exception`,
  `test_find_all_logs_and_rethrows_when_builder_throws`,
  `test_find_paginated_logs_and_rethrows_when_builder_throws`,
  `test_restore_logs_and_rethrows_when_model_throws`,
  `test_find_by_id_logs_and_rethrows_when_model_throws`,
  `test_delete_logs_and_rethrows_when_model_throws`,
  `test_save_rethrows_unknown_throwable_from_model` all construct a mock
  `CookieModel` and bypass the database entirely. They live in a class that
  extends `IntegrationTestCase`, which still pays the full migration cost
  for each one (50+ schema operations per test) and still constructs the
  real `CookieRepository` in `setUp()` — only to have the test create a
  second one with the mocked model. The class is mislabelled and the cost
  is hidden.
  Round-2 r07 noted that the SQLite-only environment cannot reproduce
  duplicate-key on MySQL because NULL-vs-NULL is distinct; the comment at
  `:644-647` admits this and justifies the mock. That's correct logic but
  the mock test belongs in `tests/Unit/Domain/Cookie/Repositories/...`,
  not in the integration folder.
- **Why this is a template defect:** A clone will copy this dual-shape
  file and forever conflate "I tested error mapping" (mocked) with "I
  tested storage" (real DB). The wrong half can be deleted in a refactor
  without anyone noticing the other half went with it. Also the
  `#[AllowMockObjectsWithoutExpectations]` attribute at line 19 silently
  weakens PHPUnit's risky-test detection for the whole class.
- **Suggested fix:** Extract the eight mocked methods into
  `tests/Unit/Domain/Cookie/Repositories/CookieRepositoryErrorMappingTest.php`
  extending `UnitTestCase`. Keep the integration class purely
  real-DB. Remove the class-level `AllowMockObjectsWithoutExpectations`
  attribute; per-method scoping is more honest. Then add a MySQL-guarded
  duplicate-key integration test (`if ($db->DBDriver !== 'MySQLi') $this->markTestSkipped()`)
  so the real catch path gets exercised when the MySQL lane runs.

### F3 — HIGH — Composite UNIQUE `(tenant_id, name, deleted_at)` is never exercised; NULL-vs-NULL gap unseen
- **Location:** Migration `app/Database/Migrations/2025-01-21-000001_CreateCookiesTable.php`
  (composite unique at line ~130 per r06 V8); absence in
  `CookieRepositoryTest.php` and `CookieOptimisticLockingTest.php`.
- **Observation:** No integration test inserts two cookies with the same
  `name` and `tenant_id IS NULL` and asserts the second one fails. Round-2
  r06 V8 + r07 §3 already proved both MySQL and SQLite treat NULLs as
  distinct under composite UNIQUE, so the constraint is decorative on every
  row written by the repository (which never writes `tenant_id`). The
  `existsByName` tests at `:372-403` only assert the application-level
  guard; they don't assert that the DB constraint backs it up. Result: the
  template advertises "uniqueness across tenants with soft-delete recovery"
  and nothing proves either engine enforces it.
- **Why this is a template defect:** Every cloned domain will inherit
  this exact migration pattern. The clone author will believe their
  `(tenant_id, name, deleted_at)` index is a safety net; in reality the
  only safety is `existsByName()` (a check-then-act race).
- **Suggested fix:** Add three tests:
  (1) Two `tenant_id=NULL` rows with same name should fail at the DB level
  (currently they don't — the test should be a `markTestSkipped` with a
  TODO that flips to the assert once `tenant_id` becomes NOT NULL or the
  index becomes functional);
  (2) Soft-deleting one row and inserting a same-named replacement
  succeeds (round-trip);
  (3) Restoring the soft-deleted row while a same-named replacement
  exists must throw (currently `restore()` doesn't check this — the
  missing test hides a production bug).

### F4 — HIGH — Feature tests assert on the view-PATH string `cookies/index` instead of rendered content
- **Location:** `tests/Feature/Cookie/CookieCrudTest.php:21, 71, 195, 218`
  (`assertSee('cookies/index' | 'cookies/show' | 'cookies/create' | 'cookies/edit')`).
- **Observation:** These are the literal view file path strings. CI4's
  default `View` class outputs the path as a debug comment (or it leaks via
  `KINT`/toolbar) — the assertions pass as long as the layout renders the
  shell, regardless of whether the table, the form, or the data is present.
  `test_index_displays_paginated_cookies` (`:24-36`),
  `test_index_supports_pagination` (`:38-48`),
  `test_index_supports_search` (`:50-60`), and
  `test_list_page_shows_only_active_cookies` (`:427-438`) literally have a
  comment "Should see some cookies (pagination applies)" but no
  corresponding assertion. The sister file `CookieQueryE2ETest.php` (slice
  scope) DOES the right thing — it asserts on the actual cookie names —
  but the broken pattern is still in `CookieCrudTest`. Two
  contradictory examples ship in the same template.
- **Why this is a template defect:** A `sed`-clone will copy
  `CookieCrudTest` (it's the bigger, more "complete-looking" file with the
  CRUD comment headers). The clone gets content-blind assertions for
  Order/Invoice/etc. and the `CookieQueryE2ETest` precedent will go
  unnoticed because it sits in a separate file with a less obvious name.
- **Suggested fix:** Delete the `assertSee('cookies/...')` lines from
  `CookieCrudTest` and replace with `assertSee($cookie->getName()->getValue())`
  patterns mirroring `CookieQueryE2ETest`. Either delete `CookieCrudTest`
  in favour of the E2E file or merge them so the template only ships one
  feature-test shape.

### F5 — HIGH — `loginAsAdmin` bypasses the real auth flow; the only logged-in identity is admin
- **Location:** `tests/Support/FeatureTestCase.php:99-118` (session-only
  seed) + `:120-143` (`seedActiveAdminUser` writes a bogus argon2id hash);
  every test in `CookieCrudTest.php` and `CookieQueryE2ETest.php`.
- **Observation:** The helper writes
  `$argon2id$v=19$m=65536,t=4,p=1$Zm9v$ + str_repeat('a', 43)` which is a
  syntactically valid envelope but `password_verify()` returns false
  against any plaintext. The login flow at `POST /auth/login` is therefore
  unreachable through any feature test. Furthermore, there is no
  `loginAsCustomer()` helper, so the suite cannot demonstrate that a
  non-admin reaching `/cookies` (or any future admin-gated route) is
  rejected. Round-2 r07 §1.2 already raised this; nothing changed.
- **Why this is a template defect:** Every cloned domain inherits the
  same auth fixture. When a clone adds a role gate (e.g.
  `filter: role:manager` on `/invoices`), the existing feature tests still
  pass because they're all admin — the gate is invisible. The template
  encourages the bug class.
- **Suggested fix:** Add `loginAs(User $user)` taking a real factory
  result; rewrite `seedActiveAdminUser` to use
  `HashedPassword::fromPlaintext('TestAdmin123!')` so `POST /auth/login`
  can be exercised end-to-end; ship a sample
  `test_non_admin_cannot_access_cookies_admin_route` that the clone author
  can adapt.

### F6 — HIGH — `test_find_paginated_orders_by_created_at_desc` uses `sleep(1)`
- **Location:** `tests/Integration/Repositories/CookieRepositoryTest.php:355-366`.
- **Observation:** `sleep(1)` to force a timestamp delta is a 1-second
  tax per run, signals the absence of a monotonic clock test helper, and is
  the kind of thing that doubles to 2s when a cloned domain copies the
  test. With ~25 active integration tests already paying a full migration
  cost per test (see `IntegrationTestCase::$refresh = true`), the suite
  has no budget for cargo-culted `sleep(1)`s.
- **Why this is a template defect:** A clone will copy the
  `sleep(1)` pattern into every `*RepositoryTest::test_find_paginated_orders_by_created_at_desc`.
  Five domains in, the suite gates on 5+ seconds of pure wall-clock waste.
- **Suggested fix:** Use `Cookie::reconstitute()` with explicit
  `createdAt` strings (the factory already supports this — see
  `CookieFactory::createPersistedCookie` at line 51). Or stamp the rows
  directly in the DB after insert via `\Config\Database::connect('tests')->table('cookies')->update(...)`.

### F7 — HIGH — Pagination edge cases NOT covered: beyond-last-page, very large offset, perPage=0, page=-1
- **Location:** `CookieRepositoryTest::test_find_paginated_*` (`:272-366`).
- **Observation:** The tests cover page 1, page 2, search term, empty.
  They do NOT cover: `page=4` when `lastPage=3` (expected: empty data,
  page=4, lastPage=3 — or clamp to lastPage?), `page=-5` (negative),
  `perPage=0` (division-by-zero / infinite loop?), `perPage=999999`
  (memory blow-up?). The READ side `CookieQueryRepositoryTest`
  (`:100-134`) does cover clamping — the WRITE side
  `CookieRepository::findPaginated` does not, and there's no test to
  confirm whether it should. A clone inheriting only the write-side test
  shape will inherit the gap.
- **Why this is a template defect:** Two repositories on the same
  aggregate must agree on pagination semantics. The template ships
  asymmetric coverage and the clone author has no signal that the write
  side is missing those tests.
- **Suggested fix:** Mirror the four `CookieQueryRepositoryTest` clamp
  tests on `CookieRepositoryTest::findPaginated`. If the write-side
  paginator does NOT clamp, fail the test loudly so the design decision is
  visible.

### F8 — MEDIUM — `IntegrationTestCase::setUp()` eagerly constructs `CookieRepository` for every test in every domain
- **Location:** `tests/Support/IntegrationTestCase.php:48-63`.
- **Observation:** `$this->cookieRepository = new CookieRepository($logger, $loggingConfig);`
  in the base class. Every integration test for Notifications, Storage,
  Settings, User, etc. constructs a `CookieRepository` it doesn't need.
  Round-1 already flagged this; persists in current source. A constructor
  signature change in `CookieRepository` breaks every Integration test in
  the repo simultaneously. The same is true for `FeatureTestCase:74-83`
  which goes one worse — it calls `Services::repository('cookieRepository')`
  in every feature test setup regardless of domain.
- **Why this is a template defect:** Clones will follow the same
  pattern: drop "their" repository as a property on the base test case.
  Eventually every base test case is a god-object dependency-bag of every
  domain's repository.
- **Suggested fix:** Remove the `$cookieRepository` property from
  `IntegrationTestCase` and `FeatureTestCase`. Move it to a Cookie-specific
  trait or a subclass `CookieIntegrationTestCase` that only the Cookie
  tests extend. Document the pattern so clones follow it.

### F9 — MEDIUM — Tenant filtering integration test directly inserts rows via raw `Database::connect()` bypassing the repository
- **Location:** `tests/Integration/Repositories/CookieQueryRepositoryTest.php:158-195`
  and `:141-149` (`test_format_price_falls_back_to_raw_for_malformed_value`).
- **Observation:** Both tests insert rows via
  `\Config\Database::connect('tests')->table('cookies')->insert([...])`
  with hand-rolled column maps including `version => 1` and `is_active => 1`.
  This bypasses VO validation. If a future column is added (e.g. `weight`
  NOT NULL), these raw inserts silently break with no clear failure
  surface (the `version => 1` field rotates from "default" to "ignored by
  the model" and the model may auto-populate it differently). The comment
  at `:162-163` explains why ("The write-repo path uses null tenant
  context by default") — but the right fix is to make the write repo
  accept an explicit tenant_id, not to bypass it in tests.
- **Why this is a template defect:** A clone will see "raw insert is
  acceptable in integration tests" and use it everywhere. The whole point
  of the Test Data Builder pattern at `CookieFactory` is undermined.
- **Suggested fix:** Add `CookieFactory::createForTenant(int $tenantId, array $overrides)`
  that returns a Cookie entity already stamped, and pass it through a
  repository constructed with the right `TenantContext`. Keep the raw
  insert ONLY for the malformed-price case (where the point is to bypass
  the VO).

### F10 — MEDIUM — `assertFlashMessage('error')` without a specific message — wrong-error-still-passes
- **Location:** `CookieCrudTest.php:128, 148, 164, 180, 292, 313, 348, 372`.
- **Observation:** Eight call-sites assert "some error happened" without
  pinning the message. A validation error that fires `error: too short`
  passes the same test as `error: unique key violation` and as
  `error: database unreachable`. The "duplicate name" test at `:131-149`
  cannot distinguish between "duplicate-key DomainException" and
  "validation error on a typo" — and the test name promises it's
  testing the former.
- **Why this is a template defect:** Clones inherit "loose error
  assertion." Bugs that swap one error message for another (e.g. a
  refactor that accidentally maps duplicate-key to "internal error") slip
  through. Combined with F2, the duplicate-key happy-path is now
  asserted in a unit-style mocked test AND in a feature test that doesn't
  pin the message — two assertions about the same thing, neither
  conclusive.
- **Suggested fix:** Replace `assertFlashMessage('error')` with
  `assertFlashMessage('error', 'must be unique')` etc., mirroring the
  pattern at `:112, 273, 363` where the success-flash IS pinned.

### F11 — MEDIUM — `test_complete_create_update_delete_journey` is a single 50-line monolith with seven assertions and no isolation
- **Location:** `CookieCrudTest.php:379-425`.
- **Observation:** The "journey" test creates, fetches, updates, deletes
  in one method. If step 3 fails, you can't tell whether step 1 worked.
  Reads "all the way through the stack" in a way that obscures which step
  broke. The line
  `$cookie = $this->cookieRepository->findAll()[0];` (line 396) silently
  trusts that `findAll()` is ordered such that the cookie we just created
  is at index 0 — true today via "ORDER BY created_at DESC" but couples
  the test to internal ordering that's not the test's contract.
- **Why this is a template defect:** Clones will copy this "journey
  test" shape per domain. A monolithic test per domain × N domains =
  unreadable failures.
- **Suggested fix:** Drop the journey test or break it into 7 small
  tests with shared setup via a trait. At minimum, fetch the cookie by a
  known property rather than `findAll()[0]`.

### F12 — LOW — `test_index_route_supports_explicit_page_parameter` asserts only `assertOK()` on both pages
- **Location:** `tests/Feature/Cookie/CookieQueryE2ETest.php:54-68`.
- **Observation:** The test seeds 25 cookies, fetches page 1 and page 2,
  and asserts only status 200. It doesn't assert that page 1 differs from
  page 2 or that any specific cookie name appears on either page. A
  pagination bug that puts the same cookies on both pages would pass.
  Same file does it right for the other tests; this one is the regression
  shape.
- **Why this is a template defect:** Inconsistent rigour in the same
  file → clone authors will fall into "this is the easy-mode pagination
  test" trap.
- **Suggested fix:**
  `$page1->assertSee('E2E Pager Cookie 25')` and
  `$page2->assertSee('E2E Pager Cookie 15')` (or whatever the per-page
  boundary is) to pin the boundary.

### F13 — LOW — `test_save_updates_only_changed_fields` is misnamed; it always changes a field
- **Location:** `CookieRepositoryTest.php:135-155`.
- **Observation:** Despite the name, the test renames the cookie
  (`'Same Name Updated'` ≠ `'Test Cookie'`) — `name` always changes.
  The test does NOT exercise a "save with identical payload" path; that
  path is the exact MySQL `affectedRows()` no-op pitfall called out in
  r07 §1.1. The test name suggests it covers the case; the body does not.
- **Why this is a template defect:** A clone reading "this is the
  partial-update test" believes the case is covered. It isn't.
- **Suggested fix:** Rename to `test_save_updates_when_only_one_field_differs`
  (truthful) and add a separate
  `test_save_idempotent_when_payload_unchanged` (the missing case — will
  fail on MySQL today; document the skip).

### F14 — LOW — `CookieOptimisticLockingTest::test_concurrent_modification_preserves_winners_write` catches DomainException with empty body but suppresses no message check
- **Location:** `tests/Integration/Repositories/CookieOptimisticLockingTest.php:134-138`.
- **Observation:**
  ```php
  try { $this->cookieRepository->save($readerB); }
  catch (DomainException) { /* expected */ }
  ```
  The catch is unscoped (any DomainException matches — including a
  duplicate-name domain exception or any other domain exception that
  might happen to fire during save). The test relies on the assumption
  that only the optimistic-lock error can throw here. Slightly fragile;
  the sibling test at `:96-99` correctly uses
  `$this->expectExceptionMessage('modified by someone else')`.
- **Why this is a template defect:** Two different error-handling
  styles in the same file. Cloner picks one at random.
- **Suggested fix:** Replace the bare `catch` with
  `$this->expectException` / `$this->expectExceptionMessage` pattern, OR
  inside the catch assert `str_contains($e->getMessage(), 'modified by someone else')`.

### F15 — LOW — CSRF is silently disabled in test env; feature tests cannot verify CSRF rejection
- **Location:** `app/Config/Filters.php:95` (per round-2 r07 §3 bullet 1)
  combined with `tests/Feature/Cookie/CookieCrudTest.php` (all POSTs).
- **Observation:** Every `$this->post('/cookies', ...)` in
  `CookieCrudTest` succeeds without a CSRF token because the filter is
  bypassed under `CI_ENVIRONMENT=testing`. No test asserts what happens
  when the token IS missing in production. The template ships an
  "all good" signal that doesn't reflect production reality.
- **Why this is a template defect:** Clones will believe their CSRF
  protection is tested; it isn't. First production deploy where someone
  removes the CSRF middleware accidentally: undetected.
- **Suggested fix:** Add at least one `test_post_without_csrf_token_is_rejected_in_production_mode`
  that temporarily switches `CI_ENVIRONMENT` or re-runs the filter
  programmatically. Or document the gap loudly in the feature test
  base class.

### F16 — INFO — Hard-coded test data won't sed-clone cleanly
- **Location:** `CookieFactory.php:14-43`, plus literally every
  `'Chocolate Chip'`, `'Vanilla Cookie'`, `'Snickerdoodle E2E'` in the
  feature tests.
- **Observation:** A `sed s/Cookie/Invoice/g` produces
  `'Chocolate Chip'` → `'Chocolate Chip'` (untouched — the strings have
  no `Cookie` substring) and `CookieName` → `InvoiceName` (correct). The
  *factory defaults* survive the sed but become absurd for the new
  domain: an `InvoiceFactory` whose default name is `'Chocolate Chip Cookie'`
  is a nonsense default that will be copy-pasted, missed, and become
  visible weeks later when someone reads a test failure that shouts
  "Chocolate Chip Cookie not found" inside a billing module.
- **Why this is a template defect:** The "domain-flavoured fixture
  data" pattern is contagious. Every default in
  `CookieFactory::createCookie` should be generic enough that a clone is
  not embarrassing.
- **Suggested fix:** Either use generic defaults
  (`name => 'Sample Item'`) or generate per-test names via
  `sprintf('%s %d', $this->className, $i)`. Document in
  `domain-scaffolding` skill that factory defaults must be renamed
  domain-by-domain.

### F17 — INFO — `#[AllowMockObjectsWithoutExpectations]` at class level on CookieRepositoryTest
- **Location:** `tests/Integration/Repositories/CookieRepositoryTest.php:15, 19`.
- **Observation:** Class-level attribute weakens risky-test detection for
  every method, not just the eight that use mocks. Even the 30+ real-DB
  methods are now exempt from "you declared a mock but didn't expect
  anything on it" warnings.
- **Why this is a template defect:** Clones get a class-wide opt-out
  they may not realise they have.
- **Suggested fix:** Move to per-method scope, OR (better, see F2)
  extract the mocked methods into a unit-test class and remove the
  attribute from the integration class entirely.

## Missing tests

Real-DB or end-to-end scenarios not covered:

1. **Idempotent re-save** (`save()` of an in-memory aggregate whose state
   matches the DB row): would expose the `affectedRows()` MySQL
   false-positive in the optimistic-lock check (r06 V5 / r07 §1.1).
2. **Soft-delete + recreate same name** at the DB-constraint layer: would
   expose the composite-UNIQUE NULL-vs-NULL gap (r06 V8).
3. **Restore-conflict** scenario: cookie A soft-deleted, cookie B created
   with same name, attempt to restore A → must fail. No test exists; the
   production `restore()` doesn't even check.
4. **Tenant isolation**: two `TenantContext`s, write-side AND read-side
   isolation between them. `CookieQueryRepositoryTest::test_apply_tenant_filter_is_active_when_context_injected`
   does it for reads; nothing does it for writes.
5. **Pagination beyond last page** (`page=99` when `lastPage=3`).
6. **`perPage=0` and `page=-1` clamp** on the **write-side** repository
   (the read-side has it).
7. **Empty body POST** to `/cookies` (just an empty array).
8. **Concurrent write race that doesn't serialize on SQLite** — needs a
   `pcntl_fork` or a MySQL-only test. Currently impossible by
   construction.
9. **`existsByName` while the same name exists in a soft-deleted row**:
   asserted at `:397-403` (returns true) — but no test asserts the
   complementary case (`existsByNameExcludingId` for the same row should
   return false, i.e. allow renaming back to your own old name).
10. **CSRF rejection** on POST in production-mode (see F15).
11. **Anonymous visitor redirect** on `GET /cookies` — `authenticateByDefault = true`
    everywhere in this slice; no opt-out test.
12. **Auth-layer role gate**: cookie routes do not check role; a future
    `role:admin` filter on `/cookies/create` cannot be regression-tested
    because no `loginAsCustomer()` helper exists (F5).
13. **JSON / XML response** on `/cookies/:id`: only HTML rendering is
    asserted. If the template later supports content negotiation, the
    test shape is wrong.
14. **Migration roundtrip** (`migrate` → `migrate:rollback` → `migrate`)
    for the cookies table: no test asserts schema symmetry. Round-2 r07 §3
    bullet 6 already noted this; still missing.

## What is correct / praiseworthy

- `CookieOptimisticLockingTest` has the right shape (3 tests covering
  version-on-insert, version-on-update, race detection + winner
  preservation). Limitation is the engine, not the test design.
- `CookieQueryE2ETest` exemplifies the right feature-test posture
  (content assertions on cookie names; explicit empty-table case; explicit
  search filter; tenant filter via DI). If `CookieCrudTest` matched this
  rigour the template would be in much better shape.
- `IntegrationTestCase` and `FeatureTestCase` correctly set
  `$refresh = true` so the DB resets between tests — no test pollution
  via shared state.
- `phpunit.xml.dist` has `failOnRisky="true"` and
  `failOnWarning="true"` — the suite refuses to silence tests. Good.
- `CookieRepositoryTest::test_save_drains_pending_events_to_injected_dispatcher`
  (`:589-628`) is a model integration test — it wires a real dispatcher,
  subscribes a real listener, exercises the full aggregate-update path.
  Other domains' first event-drainage test should be modelled on this.
- `CookieFactory` is used consistently in tests (not raw inserts —
  except the two intentional cases at F9 which are documented inline).
  Test Data Builder pattern is honoured.
- `CookieQueryRepositoryTest::test_format_price_falls_back_to_raw_for_malformed_value`
  (`:136-156`) is the right way to test a defensive fallback — direct
  DB corruption + observed behaviour.
- `restore()` tests cover 4 cases (active, soft-deleted, missing, not
  deleted) — full state-machine coverage. Good shape for clones to copy.

## Top 3 fixes before cloning

1. **Drop `force="true"` on `database.tests.DBDriver` in
   `phpunit.xml.dist:67` and add a MySQL CI lane.** Until the suite
   actually runs against MySQL, the optimistic-locking + composite-UNIQUE
   + collation claims are not certified. Round-2 r07 already prescribed
   this; the trickle-down effect on every cloned domain is the most
   damaging item in the template.

2. **Delete or rewrite `CookieCrudTest` to match `CookieQueryE2ETest`'s
   content-assertion style; remove every `assertSee('cookies/...')`
   path-string assertion.** The two coexisting feature-test patterns
   guarantee future domains pick the wrong one. Pair this with replacing
   `assertFlashMessage('error')` (no pinned message) with the
   specific-message variants.

3. **Extract the eight `createMock(CookieModel)` methods from
   `CookieRepositoryTest` into a new unit-test class
   `tests/Unit/Domain/Cookie/Repositories/CookieRepositoryErrorMappingTest.php`,
   and remove the class-level `#[AllowMockObjectsWithoutExpectations]`
   attribute.** The current file is a misleading hybrid; the clone author
   will inherit and amplify the confusion. Then move the
   `$cookieRepository` property off `IntegrationTestCase` / `FeatureTestCase`
   so future-domain base classes don't grow per-domain dependency bags
   (F8).

---

**Severity counts:** CRITICAL 2 | HIGH 5 | MEDIUM 3 | LOW 5 | INFO 2
**Top finding:** `phpunit.xml.dist` hard-locks the test DB to SQLite via `force="true"`, so the entire optimistic-locking + composite-UNIQUE + `affectedRows()` test surface is validated against the wrong engine — every cloned domain inherits the false confidence.
