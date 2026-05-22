# R07 — Testing posture

Date: 2026-05-20
Scope: Are the tests proving what the team thinks they prove?
Inputs reviewed: `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/Support/{Unit,Integration,Feature}TestCase.php`, `tests/Unit/Domain/Cookie/Entities/CookieTest.php`, `tests/Integration/Repositories/CookieRepositoryTest.php`, `tests/Integration/Repositories/CookieOptimisticLockingTest.php`, `tests/Integration/Outbox/EventOutboxTest.php`, `tests/Integration/Jobs/JobQueueTest.php`, `tests/Integration/Projections/CookieReadModelProjectionTest.php`, `tests/Integration/Numbering/DocumentNumberingServiceTest.php`, `tests/Unit/Infrastructure/Http/Middleware/IdempotencyMiddlewareTest.php`, `tests/Feature/Cookie/CookieCrudTest.php`, `tests/Integration/Security/{Authentication,Penetration}Test.php`, the Cookie repo + relay + worker + numbering production code, and `app/Database/Migrations/*`.

---

## 1. Verified test gaps (tested-but-not-really)

### 1.1 `affectedRows()` semantics are tested only on SQLite — production behaviour differs

Three production code paths gate on `affectedRows() === 1`:

- `app/Models/Cookie/CookieRepository.php:389` — optimistic-lock check.
- `app/Infrastructure/Outbox/EventOutboxRelay.php:150` — outbox-row claim.
- `app/Infrastructure/Jobs/JobWorker.php:141` — job claim.

SQLite's `sqlite3_changes()` counts WHERE-matched rows. MySQL's `mysqli_affected_rows()` counts **rows actually changed** (unless `CLIENT_FOUND_ROWS` is set; CI4 does **not** set it). The divergence the test suite hides:

- `CookieRepositoryTest::test_save_updates_only_changed_fields` (lines 128–148) renames the cookie, so MySQL sees a real diff → 1 affected row → green. The case the suite never exercises is "client saves an aggregate where the in-memory state happens to equal the row already on disk." On MySQL `affectedRows()` returns 0 and `updateWithOptimisticLock()` will throw `DomainException::concurrentModification` even though no concurrent writer exists. On SQLite it returns 1 and the same call succeeds. Round 1 finding #4 flagged the production bug; the test suite cannot expose it because of the in-memory driver choice. **False confidence**.
- `EventOutboxTest::test_relay_does_not_pick_up_rows_not_yet_available` (lines 78–92) and the claim-race scenarios run a single worker per process. The real failure mode of `EventOutboxRelay::claim()` — "`update()` returns `true` even when zero rows match on most CI4 drivers, so worker B re-dispatches the same event after worker A already claimed it" (Round 1 finding #11 sub-issue) — is structurally untestable inside a single PHP process against `:memory:` SQLite. There is no integration test that spawns two relay instances. The claim test therefore proves only the happy path.
- `JobQueueTest` (lines 36–106) has the same single-worker shape. The "delayed jobs" and "queue isolation" tests are deterministic non-race scenarios.

### 1.2 `FeatureTestCase::loginAsAdmin()` skips the entire auth flow

`tests/Support/FeatureTestCase.php:86–105` builds a session row directly (`mockSession()` + `$session->set('user_id', …)`) and inserts a user with `password_hash = '$argon2id$v=19$m=65536,t=4,p=1$Zm9v$' . str_repeat('a', 43)` (line 115). This:

- Is a syntactically-valid argon2id envelope but the salt/hash are bogus — `password_verify()` against any plaintext returns false. Every feature test that depends on `loginAsAdmin()` is therefore **structurally incapable of also exercising the real `POST /auth/login` controller**. The flow under test is the session-cookie short-circuit at `SessionAuthMiddleware::before` (`app/Infrastructure/Auth/Middleware/SessionAuthMiddleware.php:46-73`), not the credential check at `LoginUserHandler`.
- Skips `LoginUserHandler::handle` (rate limiting, lockout counter increment, `failed_login_attempts` reset on success, refresh-token write, `last_login_at` stamping). None of these are covered by feature-level tests.
- Skips `AuthController::login` (the only place that validates CSRF for the login form, sets the regenerated session id, populates flash messages on failure).
- Skips role check entirely. `SessionAuthMiddleware` validates only that `user_id` resolves to an active user (`SessionAuthMiddleware:64`). It does **not** check role. The session blob `loginAsAdmin` writes contains `'role' => 'admin'`, but nothing in the middleware reads it, so any test that grants the session can hit admin-only routes. The Round 1 CRITICAL "web admin user routes have no role gate" (finding #13) is therefore invisible to the feature suite because **the only logged-in identity the suite knows is `admin`** — there is no `loginAsCustomer()`, so the suite cannot demonstrate a customer reaching `admin/users`.

In short: `loginAsAdmin` is a session-fixture, not an authentication exercise. Every feature test that uses it inherits the limitation.

### 1.3 `CookieCrudTest::assertSee('cookies/index')` and siblings

`tests/Feature/Cookie/CookieCrudTest.php:21, 71, 195, 218` assert `assertSee('cookies/index' | 'cookies/show' | 'cookies/create' | 'cookies/edit')`. These are looking for the view-path string (CI4's debug toolbar / view path comment) in the body, **not** for the rendered content. Implications:

- The test passes as long as the view file path appears once in the response — almost any rendering, even a stub view, satisfies this.
- A regression that broke the list table (e.g. partials/_form_field swap) but still rendered the view shell would not be caught.
- `test_index_displays_paginated_cookies`, `test_index_supports_pagination`, `test_index_supports_search`, `test_list_page_shows_only_active_cookies` (lines 24–60, 427–438) call only `assertOK()` with comments like "// Should see some cookies (pagination applies)" or "// The view should only display active cookies by default". The behaviour the comments describe is **not asserted**.

These are the most-likely-to-break clone-template tests. They will be copy-pasted into Order, Invoice, etc. and inherit the same content-blindness.

### 1.4 `CookieReadModelProjectionTest` proves the projection class works *if* you call it

Round 1 finding #2 establishes that `ProjectionRegistry` is never wired in production: no service factory, no caller in `app/`, `CookieServiceProvider::registerEvents` never subscribes the projection. The integration test (`tests/Integration/Projections/CookieReadModelProjectionTest.php`) explicitly constructs `ProjectionRegistry` and dispatches events through it (lines 180–195). The class works **when assembled by the test**; nothing in the suite proves that production code path assembles it the same way. The test is therefore green while the read model is dead in production. **False confidence — high impact.**

### 1.5 `DocumentNumberingServiceTest` cannot exercise the lost-update race

Production code at `DocumentNumberingService::allocate` (`app/Infrastructure/Numbering/DocumentNumberingService.php:58-80`) wraps a plain `SELECT` and a plain `UPDATE` in a transaction; the docblock advertises `SELECT ... FOR UPDATE` (line 26) but the code never emits one. SQLite is single-writer, so even an honest race against a SQLite test would serialise. Two-process MySQL tests would expose the duplicate-allocation bug (Round 1 finding #10), but the suite has none. The "subsequent allocations increment gaplessly" test (lines 25–37) and "different scopes" test (lines 39–50) succeed because they're sequential.

### 1.6 Tenant-scoping enforcement is untested everywhere

`grep -rn "tenant_id\|tenantId" tests/` returns one hit (`SettingsServiceTest`). Cookie repository tests do not seed two tenants and assert isolation; query handlers do not verify tenant filtering; the projection test inserts `tenant_id => null` and asserts nothing about it. Given Round 1 finding #1 (tenant scoping is schema-only fiction), the entire multi-tenant safety net is invisible to the suite. Any future "fix" can land without a test catching the cross-tenant read. **No test asserts data isolation between tenants.**

---

## 2. Tests claiming false confidence

| Test | Claims to prove | Actually proves |
|---|---|---|
| `CookieRepositoryTest::test_save_updates_only_changed_fields` | Repository handles partial updates | Updates with one field changed succeed on SQLite. MySQL's `affectedRows=0` no-op-update is never reached. |
| `CookieOptimisticLockingTest::test_concurrent_update_throws_domain_exception` | Optimistic lock catches stale writes | Different-payload writes are detected. Idempotent re-saves (same payload) are not tested — the very case that produces the MySQL false-positive. |
| `EventOutboxTest::test_relay_delivers_pending_event_to_dispatcher` | Outbox guarantees at-least-once delivery | A single relay drains a single row in a single process. Double-claim by two workers is impossible to set up here. |
| `EventOutboxTest::test_relay_retries_when_listener_throws` | Failed listeners trigger retry | The test **subclasses `EventDispatcher` to throw at the dispatch boundary** (line 113–125). Real `EventDispatcher::dispatch` catches `\Throwable` (per Round 1 finding #3) — listener exceptions never reach the relay. The actual production behaviour ("listener throws, relay marks delivered, retry never happens") is the opposite of what the test demonstrates. |
| `CookieReadModelProjectionTest::test_registry_wires_projection_to_dispatcher` | The projection is wired | The projection works when the test wires it. Production `CookieServiceProvider` never wires it. |
| `CookieCrudTest::test_index_displays_paginated_cookies` | Pagination renders | Status code is 200. Pagination is not asserted at all. |
| `CookieCrudTest::test_list_page_shows_only_active_cookies` | Inactive cookies hidden | Status code is 200. The "only active" claim is unasserted. |
| `IdempotencyMiddlewareTest::test_replay_returns_cached_response_when_same_request` | Replays preserve response | Round 1 finding #10 sub-bullet: replay restores Content-Type but drops `Location`/`ETag`/custom headers. The test only asserts status code, body content, and the `Idempotency-Replayed` header. It does **not** assert that `Location` or other response headers survive replay. The header-stripping bug is invisible. |
| `AuthenticationSecurityTest::test_forged_jwt_token_with_invalid_signature_is_rejected` (and the 10 other pen tests) | Auth boundary is hard | Token-level rejection is verified at the **service** layer (`JwtService::validateToken`). The actual HTTP boundary (filter wiring on `admin/*`) is not tested. Round 1 finding #13 ("web admin routes have no `role:admin` filter") is wholly outside what the security suite exercises. |

---

## 3. Missing test categories

1. **Auth bypass at the HTTP boundary**
   - No feature test asserts `GET /admin/users` returns 302/403 for an authenticated **non-admin** session. The role gate is missing in production (Round 1 #13) and missing in tests in parallel.
   - No feature test verifies `web_auth` redirect for unauthenticated `GET /cookies`. The base `loginAsAdmin` runs by default; opt-out via `$authenticateByDefault = false` exists but is used only for `AuthLayoutTest` / `EmailTemplatedTest` / `HealthEndpointTest` (verified `grep -rn "authenticateByDefault = false"`).
   - No CSRF feature test — `Filters.php:95` disables CSRF when `ENVIRONMENT === 'testing'`, so even if a test attempted to verify CSRF rejection, it cannot.
   - No test for `LoginUserHandler` lockout (`failed_login_attempts >= N` → locked) at the feature layer; only the rate-limit unit tests exist.

2. **Tenant-scoping enforcement** (see §1.6).

3. **MySQL-specific behaviours**
   - No tests guarded by `if ($db->DBDriver !== 'MySQLi') { $this->markTestSkipped(...) }`.
   - `ENUM` constraints (`users.role`, `users.status`) are silently `TEXT` on SQLite. A test that inserts `role = 'sudo'` will succeed on SQLite and fail on MySQL — no such test exists, but no constraint test would catch the silent acceptance either.
   - `utf8mb4_unicode_ci` collation: `existsByName` is asserted case-insensitive at lines 381–388 of `CookieRepositoryTest`. SQLite achieves case-insensitivity via `LIKE` or `LOWER()`, MySQL via collation. The test passes on SQLite for the wrong reason if the repository uses `LIKE` directly; on MySQL it depends on the column's collation (which the migration pins, but the repository's WHERE clause may not respect — uncovered).
   - `FOR UPDATE` row locking — present in zero production code paths despite Numbering's docblock advertisement (Round 1 #10). No test attempts to assert a `SELECT ... FOR UPDATE` was emitted.
   - `TINYINT(1)` vs SQLite `INTEGER`: `is_active = 1` reads correctly on both, but JSON-cast paths (none today) would diverge.
   - Composite `UNIQUE(tenant_id, name, deleted_at)` with NULL semantics: SQLite and MySQL agree on NULL-distinctness for UNIQUE indexes (both treat NULL as distinct), so this specific divergence is NOT a SQLite/MySQL gap — but the test suite does not assert the constraint fires at all on either engine. The Round 1 #5 finding is that the constraint is theatre because `tenant_id` and `deleted_at` are always NULL on live rows; the only test that would have caught this (`existsByName` after a re-save with same name + null tenant) doesn't exist.

4. **Concurrent / worker tests**
   - No fork / `pcntl_fork` / multi-process tests for outbox claim, job claim, numbering allocation.
   - No "interrupted worker" tests — what happens when a worker crashes after `update(status='in_flight')` but before `update(status='delivered')`. The `in_flight` row will sit forever; production code has no lease timeout. No test models this.
   - No idempotency-key TTL test — `IdempotencyMiddlewareTest` exercises store/replay but no test asserts that the cache entry expires (TTL not asserted; `idempotency_keys` table has a `created_at` but no test verifies cleanup).

5. **Performance / regression**
   - No baseline assertions (`assertLessThan(50ms, $duration)`).
   - No `phpbench` config, no `tests/Performance/` directory.
   - `CookieRepositoryTest::test_find_paginated_orders_by_created_at_desc` (lines 348–359) calls `sleep(1)` to force timestamp ordering — that's a 1s tax per test run and a sign there's no monotonic-clock test helper.

6. **Migrations / schema**
   - No test asserts that `php spark migrate` followed by `php spark migrate:rollback` is symmetric. Round 1 reported `AddNameToUsersTable::down()` is a no-op (`AddNameToUsersTable:27-33`) — a migration roundtrip test would have caught it.
   - No test asserts `php spark migrate` is idempotent against a partially-migrated database.

7. **`auth_ui_helper` (the UI security boundary)**
   - Round 1 #15 already flagged "no tests touch `auth_ui_helper.php`". One Integration test (`tests/Integration/Auth/AuthUiHelperTest.php`) exists, contrary to that finding — but check its coverage of the `\Throwable` swallow path. The bug-of-record is that `can()` returns `false` on any throwable; a misconfigured PermissionService → admin UI silently hides every action button. Whether this is tested needs verification.

8. **Outbox writer is never even loaded**
   - `EventOutboxWriter` has tests (the `Outbox/EventOutboxTest.php` constructs one directly), but **no production caller**. The integration test exercises a class with zero live wiring. False confidence by construction.

---

## 4. SQLite-vs-MySQL divergence impact

| Behaviour | SQLite (tests) | MySQL (prod) | Test impact |
|---|---|---|---|
| `affectedRows()` after no-op `UPDATE` | 1 (rows matched) | 0 (rows changed) | Optimistic-lock check (`CookieRepository:389`) raises false `ConcurrentModification` in prod; tests are green. **CRITICAL.** |
| Outbox `claim()` `affectedRows()` after no-op | 1 | 0 | `claim()` returns `true` falsely on prod under no-op race; tests cannot reproduce. **HIGH.** |
| `ENUM` constraint | TEXT (any value) | rejects out-of-set | Inserting `role='superuser'` succeeds in tests, fails in prod. **MEDIUM.** |
| Composite `UNIQUE(tenant_id, name, deleted_at)` with NULLs | NULL distinct (allows multiple) | NULL distinct (allows multiple) | Same behaviour — divergence is not the bug; the bug is the constraint is theatre because columns are always NULL. **HIGH (logic, not driver).** |
| `utf8mb4_unicode_ci` collation | n/a (no collation) | case+accent-insensitive | `existsByName` case-insensitivity may pass on SQLite (LIKE) and silently fail on MySQL if the WHERE clause doesn't respect collation. **MEDIUM.** |
| `SELECT ... FOR UPDATE` | parser accepts, ignored | row lock | Lost-update race in `DocumentNumberingService` is structurally invisible in tests. **CRITICAL.** |
| Foreign keys | `foreignKeys=true` in phpunit.xml.dist | enabled in InnoDB | Test env is **stricter** than prod here — `permissions_schema` join tables have no FK in migrations (Round 1 migration finding), so prod allows orphaned rows that tests reject. Inverted divergence. **MEDIUM.** |
| Transaction isolation | SERIALIZABLE (effectively) | REPEATABLE READ | The "two readers see version 1, A wins" race in `CookieOptimisticLockingTest` (lines 61–100) is serialised by SQLite. On MySQL with two real connections, the visibility is different but the optimistic-lock outcome is the same. **LOW.** |
| `TINYINT(1)` boolean | INTEGER 0/1 | TINYINT 0/1 | Equivalent at the wire; PDO/mysqli cast both to int. **LOW.** |
| `mediumblob`/`longblob`, `JSON` columns | None used yet | n/a | If a future entity uses `JSON`, SQLite reads it as TEXT — equality comparisons diverge. **Future risk.** |

Mitigation the suite does not implement: a parallel CI job against MySQL 8.0 with the same phpunit run. The `phpunit.xml.dist:58-64` forces SQLite via `force="true"` — even `database.tests.*` env overrides are blocked. Round 1 already raised "Recommend a parallel MySQL CI job" (15-views-...:116); no progress here.

---

## 5. Risky-tests audit (`failOnRisky=true`)

`phpunit.xml.dist:10-11` sets `failOnRisky="true"` and `failOnWarning="true"` — good, the suite refuses to silence tests. But the same configuration means tests that **should** be marked risky are instead written without assertions and pass:

- `CookieCrudTest` lines 24–36, 38–48, 50–60, 187–196, 210–219, 427–438 each contain only `assertOK()` plus a comment about the behaviour they intend to assert. PHPUnit's risky detection fires on tests with no assertions at all; one `assertOK()` is enough to dodge the detector. These are **not** marked risky but they should be.
- `DocumentNumberingServiceTest::test_pad_length_validation` (lines 88–105) uses a manual `try/catch` + `$this->assertTrue(true)` to assert "an exception was thrown" — that pattern bypasses PHPUnit's `expectException` machinery and resembles a green test by construction. Risky-detection won't flag this because there *is* an assertion (`assertTrue(true)`); it just isn't meaningful.

I found no explicit `@group risky` or `markAsRisky` calls. No tests are *silenced* — they're written below the risky-detection threshold.

---

## 6. Other infrastructure observations

- `tests/Support/IntegrationTestCase.php:60-63` and `FeatureTestCase.php:68-70` eagerly construct `CookieRepository` in `setUp()`. Round 1 already flagged this (`15-…:111`). Confirming for the testing-posture view: any test in the Integration or Feature suite **always pays the `CookieRepository` construction cost** even when testing Notifications, Storage, Settings, etc. A constructor change in `CookieRepository` breaks every Integration and Feature test in one go. The coupling is invisible to PHPUnit but visible to test maintainers.
- `phpunit.xml.dist:43-44` excludes `app/Views` from coverage but only `app/Config/Routes.php` from Config — other Config files (Cache, Session, App, Filters) skew coverage downward.
- `phpunit.xml.dist:30` excludes `tests/_skipped` from the suite — confirmed via `ls`: 49 files in `tests/_skipped/AbiSageIntacct/*` shipped with the template (legacy domain). Not active, but the directory should be removed from the template repository, not just excluded.
- `tests/bootstrap.php:13` requires `tests/_support/bootstrap_libraries.php` (lowercase). Mixed casing with `tests/Support/` (PSR-4) — Round 1 #15 already flagged; flagging again because **`failOnRisky` won't catch a case-sensitivity load failure** if it surfaces only on a case-sensitive filesystem.
- `UnitTestCase::assertExceptionMessage` (lines 28–44) catches `\Exception` not `\Throwable` — PHP 8.4 `\Error` (`\TypeError`, `\ValueError`) escapes the helper. Round 1 already noted this.
- `failOnWarning="true"` and `displayDetailsOnTestsThatTriggerWarnings` are good — the suite will fail on deprecations, which guards against silent PHP-version drift.
- `IntegrationTestCase::$namespace = null` and `$migrate = true, $refresh = true` mean every Integration test pays a full migration cost. With ~50 active integration tests and ~25 migrations, that's ~1250 schema operations per `composer test` run.

---

## 7. Verdict on overall test posture

**The test count is high (~62 active test classes) and the structure is correct, but the suite is anchored on patterns that cannot exercise the bugs Round 1 surfaced.**

Concretely, the test posture is healthy for:
- Value-object invariants and entity behaviour (CookieTest, EmailTest, HashedPasswordTest, MoneyTest, CurrencyTest, DocumentNumberTest, etc.).
- Unit-level command/query handler contracts with mocked dependencies (CreateCookieHandlerTest, etc.).
- Single-process, single-connection happy paths of repository CRUD.

The test posture is **not** healthy for:
- The MySQL/SQLite divergence on `affectedRows()` — directly invalidates the optimistic-lock claim and the outbox-claim claim. **Two CRITICAL prod bugs the suite cannot see.**
- Production wiring of infrastructure objects (`ProjectionRegistry`, `EventOutboxWriter`, `CookieReadModelProjection`) — integration tests instantiate them by hand, hiding the absence of live registration. **CRITICAL false confidence.**
- HTTP-boundary authorization (role gates, CSRF, auth bypass). The only logged-in identity the feature suite knows is `admin`, and `loginAsAdmin` bypasses the real login. **CRITICAL gap.**
- Multi-tenant isolation. Zero meaningful tests.
- Concurrent / race-condition behaviour for outbox, jobs, numbering. Zero tests.
- Test-as-spec for views — every Cookie feature test asserts `assertOK()` plus a path-string `assertSee` that doesn't validate content. Clones will inherit content-blind tests.

**Top 5 actions to restore confidence:**

1. Add a parallel MySQL CI job. Drop `force="true"` on `database.tests.DBDriver` in `phpunit.xml.dist:62` and read from env. Run the suite twice in CI (SQLite for speed, MySQL 8.0 for correctness).
2. Write a regression test for `affectedRows() === 1` false-positive: same-payload re-save must not throw. Currently impossible to write on SQLite; will be the canonical "this proves we ran the MySQL job" test.
3. Replace `assertSee('cookies/index')` patterns with content assertions (`$result->assertSee($cookie->getName())`). Block clones from inheriting the pattern by removing the path-string assertions from the template entirely.
4. Add `loginAsCustomer()` to `FeatureTestCase` (or, better, `loginAs(User $user)` taking a real factory). Pre-hash the admin password via `HashedPassword::fromPlaintext('TestAdmin123!')` so the suite can exercise `POST /auth/login` end-to-end. Then add a test that asserts a customer hitting `GET /admin/users` is rejected — the test will fail on the current codebase, confirming Round 1 #13.
5. Wire `ProjectionRegistry` into a real service provider; add a feature test that calls `POST /cookies` and then reads `cookie_read_model` directly. The test will fail today (projection not subscribed), confirming Round 1 #2. Then write a smoke test for outbox writer: the test should assert that a saved Cookie writes to `event_outbox` — it will fail today (outbox writer is dead code), confirming Round 1 #11.

These five changes turn the test suite into something that can detect the Round 1 CRITICAL findings. Without them, the suite will keep certifying every clone as "green" while the same defects propagate.
