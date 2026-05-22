# 05 — Cookie repository + model + provider

## Files audited

- `app/Models/Cookie/CookieRepository.php`
- `app/Models/Cookie/CookieModel.php`
- `app/Models/Cookie/Traits/RepositoryLogging.php`
- `app/Models/Cookie/Traits/BusinessMetricsLogging.php`
- `app/Domain/Cookie/Ports/CookieRepositoryInterface.php`
- `app/Domain/Cookie/CookieServiceProvider.php`
- `app/Database/Migrations/2025-01-21-000001_CreateCookiesTable.php`
- `app/Database/Migrations/2026-05-20-200000_CreateCookieReadModelTable.php`

Cross-references consulted: `app/Domain/Cookie/Entities/Cookie.php`, `app/Domain/Shared/AggregateRoot.php`, `app/Domain/Shared/Exceptions/DomainException.php`, `app/Domain/Cookie/Commands/{CreateCookie,UpdateCookie,RestoreCookie}/*Handler.php`, `app/Domain/Cookie/Projections/CookieReadModelProjection.php`, `app/Config/Database.php`, `app/Config/Logging.php`.

---

## Findings

### CRITICAL

**C1. Tenant scoping is declared in schema but completely absent from the runtime path.**
`CookieRepository.php:79-120,150-296` and `CookieModel.php:30-115`. The migration creates a `tenant_id` column and a composite UNIQUE `(tenant_id, name, deleted_at)` (`2025-01-21-000001_CreateCookiesTable.php:51-56,130`), and the model lists `tenant_id` as an allowed field (`CookieModel.php:44`). But **nothing writes it, nothing reads it, nothing scopes by it**:
- `performSave()` (`CookieRepository.php:343-372`) never sets `tenant_id` on insert → every row gets `NULL`. The UNIQUE index then degenerates to `UNIQUE(NULL, name, NULL)`, and MySQL treats `NULL` as distinct in UNIQUE — meaning *duplicate names DO insert successfully* (B16 silently broken).
- `existsByName` / `existsByNameExcludingId` (`CookieModel.php:93-115`) scan globally, no `WHERE tenant_id = ?` filter.
- `findAll`, `findPaginated`, `findById`, `delete`, `restore` — none take or apply a tenant id.
- Repository ctor (`CookieRepository.php:67-77`) does not inject a tenant resolver/context. `grep` for `TenantContext|CurrentTenant|tenantResolver` returns zero hits in `app/`. So there is no place a tenant could even come from.
- The error message at line 108 — `'Cookie name must be unique within the tenant.'` — is misleading; uniqueness is global, not per-tenant.

This is the single biggest landmine for the ERP-cloning use case: every cloned entity will inherit this gap and silently allow cross-tenant duplicates the moment a real tenant is plugged in.

**C2. Duplicate-key translation has a 50/50 chance of misfiring under MySQL `NULL` semantics.**
`CookieRepository.php:100-119,122-128`. The handler relies on the DB unique index to catch concurrent inserts past the `existsByName` check, but because `tenant_id` and `deleted_at` are both `NULL` on insert and MySQL considers `NULL`s distinct in UNIQUE indexes, the composite key `(tenant_id, name, deleted_at)` will **not** raise a duplicate-key error for two rows with the same name when both have `tenant_id IS NULL` and `deleted_at IS NULL`. Net effect: race past `existsByName` produces two rows in MySQL prod with the same name — the catch block at `:105` never fires. (PostgreSQL would behave the same; only SQLite tests pass because there's nothing to race.)

**C3. `restore()` does not bump the optimistic-locking version and does not increment `updated_at` via the lock pathway.**
`CookieRepository.php:266-281`. The restore performs a raw `builder->where('id', $id)->update(['deleted_at' => null])`. This bypasses `version`, bypasses `updated_by`, and bypasses `updated_at` (the model's `useTimestamps` flag only fires through `Model::update()`, not through the raw query builder). Any in-flight `Cookie` entity another writer is holding will not see its version mismatch on a subsequent save — they'll silently overwrite the restored row. The unique index won't help because after restore there is a live row with `deleted_at IS NULL`, but a concurrent un-deleted write with the entity's stale `deleted_at = '...'` value would be allowed in (different unique tuple).

**C4. Optimistic-lock `affectedRows()` check is unsafe under MySQL `CLIENT_FOUND_ROWS=0` (the default).**
`CookieRepository.php:377-396`. `affectedRows()` returns rows *changed*, not rows *matched*. If a concurrent writer updates the row but the new payload happens to match the row's current values (e.g. an idempotent retry, an `is_active=1` re-toggle), `affectedRows()` returns `0` even though the `WHERE id = ? AND version = ?` matched. The code then calls `raiseConcurrentModification` (`:395,398-414`) and throws a false-positive. SQLite tests will pass because SQLite reports rows matched. To be bullet-proof on MySQL the lock check needs `MYSQLI_CLIENT_FOUND_ROWS` or a follow-up `SELECT version` post-update.

### HIGH

**H1. Repository writes neither `created_by` / `updated_by` / `deleted_by` audit columns nor reads them back.**
`CookieRepository.php:343-372,246-261,266-281` and `CookieModel.php:37-48`. The migration carries the audit columns (`2025-01-21-000001_CreateCookiesTable.php:94-111`) and the entity's restore handler captures `restoredBy` in the event (`RestoreCookieHandler.php:56-65`) — but nothing reaches the row. `performSave()` `$data` array (`:345-351`) omits all three. `delete()` calls `$this->model->delete($id)` which sets `deleted_at` only — `deleted_by` stays NULL. Audit trail (B10) is theoretical.

**H2. `existsByName` includes soft-deleted rows but the duplicate-key safety net cannot defend that invariant.**
`CookieModel.php:93-98`. The model uses `withDeleted()` so the handler's check rejects names that match a *soft-deleted* row. That contradicts the migration's `UNIQUE(tenant_id, name, deleted_at)` design, which was explicitly chosen to allow reuse-after-delete (`2025-01-21-000001_CreateCookiesTable.php:25-28` docblock: "soft-deleted rows do not block creation of a new row with the same name"). The handler-layer check and the DB index disagree: handler is more restrictive than schema. This breaks the documented B16 semantics — the moment someone soft-deletes "Chocolate Chip" they can never create a new "Chocolate Chip". Pick one, but they have to agree.

**H3. `isDuplicateKey()` substring match is locale-fragile and platform-incomplete.**
`CookieRepository.php:122-128`. Matches `'duplicate'`, `'unique constraint'`, `'1062'`. Missing:
- PostgreSQL: SQLSTATE `23505`, message `duplicate key value violates unique constraint` (would catch on `'duplicate'` but only if the message is English — PG localises).
- SQL Server: SQLSTATE `2627` / `2601`, message `Cannot insert duplicate key`.
- Localised MySQL: non-English server-locale errors don't contain `'duplicate'`.
- Numeric vs string compare: `str_contains($message, '1062')` is naive — any error message containing `1062` (line numbers, etc.) matches. The proper signal is `$e->getCode()` / SQLSTATE, not the human-readable message.

This is fragile in any non-English MySQL deployment.

**H4. `findAll` / `findPaginated` reuse the model builder without resetting it.**
`CookieRepository.php:422-439,447-492`. `executeFindPaginated` builds up a builder with `where('deleted_at IS NULL')`, optional `where('is_active', 1)`, optional `like('name', $searchTerm)`, then calls `countAllResults(false)` (preserves the builder), then `limit().orderBy().get()`. If `findAll` and `findPaginated` are called on the *same repository instance* in the same request, the second call's `$this->model->builder()` returns the SAME shared builder object in CI4 — predicates from call N leak into call N+1. The `false` flag at `:464` is the only thing preventing the count call from wiping the predicates, but it also preserves them for the next caller. Verify by issuing two consecutive `findAll(true)` then `findAll(false)` in one request — second call will likely return the inactive list because the `is_active=1` from the first wasn't cleared. (CI4's `Model::builder()` does NOT reset by default.)

**H5. ServiceProvider does not register `CookieReadModelProjection`.**
`CookieServiceProvider.php:168-196`. There are FIVE events (`CookieCreated`, `CookieUpdated`, `CookieDeleted`, `CookieStockChanged`, `CookieRestored`) but only FOUR handler subscriptions in `registerEvents()` — `CookieRestoredEvent` has no subscriber. Confirmed by the directory listing: `Events/CookieRestored/` contains only `CookieRestoredEvent.php`, no handler file. Worse, the `CookieReadModelProjection` (`app/Domain/Cookie/Projections/CookieReadModelProjection.php`) subscribes to all 5 events including `CookieRestoredEvent` (`:54-58`) — but the provider never wires it up. So:
- `RestoreCookieHandler` dispatches `CookieRestoredEvent` (`RestoreCookieHandler.php:59-65`) → goes into the void.
- The read-model table (`cookie_read_model`) never gets updated on any event, because the projection is registered nowhere visible in this provider.
- Result: list/search queries against the read model return stale data unless `php spark projections:rebuild cookie` is run manually.

**H6. Repository can be instantiated without a dispatcher and silently drops events.**
`CookieRepository.php:130-142`. `dispatchPendingEvents` falls back to `$cookie->pullEvents()` (which clears the buffer) when `$eventDispatcher` is `null`. Stated reason: "Drain anyway so the buffer doesn't grow unbounded." But the command handlers (`CreateCookieHandler.php:104-110`, etc.) dispatch events *themselves* using their own dispatcher — they construct event payloads directly, not from `pullEvents()`. So the only events in the bag are the ones the *entity* raises (e.g. `CookieStockChangedEvent` from `decreaseStock`/`increaseStock`). If a handler ever calls `decreaseStock` and saves through a repository with `eventDispatcher=null`, the stock-changed event is silently discarded. The fallback at `:135` masks misconfiguration as success.

**H7. Save / event dispatch is not transactional.**
`CookieRepository.php:85-120,130-142`. INSERT happens first (`performSave` at `:89`); events are then dispatched at `:97`. If the projection handler fails (or the outbox writer fails), the write side has committed but the read model is stuck. No `try { $db->transStart(); ... $db->transComplete(); }` wrapping the save+dispatch. Comment at `:92-96` acknowledges "command handler's responsibility" but the responsibility is never actually taken — handlers don't open transactions either.

### MEDIUM

**M1. Provider's `getRepository()` does no key existence check — silent KeyError.**
`CookieServiceProvider.php:238-241`. Returns `$this->repositories[$name]` with no `isset()`. If the registry's `setRepositories()` omits one of the keys declared in `getRepositories()`, PHP raises a notice and the subsequent `instanceof` check at `:87-93` will be evaluated against `null` and throw the catch-all "Invalid repository, event dispatcher or logger type injected" — masking the real error (missing key vs wrong type). A `if (!isset(...)) throw ...` would help debugging.

**M2. `LoggerFactory::create('cookie.events')` bypasses the injected logger.**
`CookieServiceProvider.php:170`. `registerCommands`/`registerQueries` inject the logger from the registry, but `registerEvents` calls the static factory directly. Inconsistent. If a test wants to mock the events logger they cannot — they have to mock the factory. Also a leak point if `LoggerFactory` is stateful.

**M3. Model has CI4 validation rules that duplicate Value-Object validation.**
`CookieModel.php:56-79`. `CookieName` / `CookiePrice` already enforce `min_length`, `max_length`, `decimal`, `greater_than`. Now the same rules live in two places — they will drift. Worse, the model validates `is_active` as `in_list[0,1]` but the repository passes `true/false` through `(int) (bool)` (`CookieRepository.php:350`) — works today, but if anyone switches the column to bool it silently breaks. Single source of truth should be the Value Object; the model rules are infrastructure-layer noise.

**M4. `findById` triggers business-metric side effect on every read.**
`CookieRepository.php:150-168` calls `trackPopularCookie($id)` at `:161`. This means **every** repository read mutates instance state (`$queryCount[$id]++`) — repositories are supposed to be stateless. Two consequences:
1. The same repository instance reused across a long-running queue worker grows `$queryCount` unboundedly.
2. The "Popular cookie" log line at `BusinessMetricsLogging.php:108-114` fires after 100 reads *in a single repository lifetime* — not 100 reads against the database. Useless metric.

**M5. Traits read `$this->logger` / `$this->loggingConfig` without an interface contract.**
`RepositoryLogging.php:24,70,80,99-113` and `BusinessMetricsLogging.php:32,49,80,95,109`. Traits assume properties exist on the using class. Works for CookieRepository, but the cloning template (`/add-domain`) will rely on this and a tiny refactor that renames the property breaks at runtime, not at PHPStan. Either move to abstract methods on the trait (`abstract protected function getLogger(): LoggerInterface;`) or expose an interface. Right now the trait reuse is "do too much": traits combine state assumptions + business logic (low-stock threshold of 10 is hardcoded at `BusinessMetricsLogging.php:45`; 10% price-change threshold hardcoded at `:76`; popularity threshold of 100 at `:105`).

**M6. Read-model migration has no UNIQUE on `cookie_id` aside from PK and no soft-delete-aware index.**
`2026-05-20-200000_CreateCookieReadModelTable.php:32-132`. `cookie_id` is the primary key (`:126`) — that's fine for upserts but composite tenant scoping is not enforced; `(tenant_id, name)` collisions would break list queries. Also no UNIQUE constraint backstop. The "available" boolean column has its own index (`:130`) but is highly low-cardinality — a tenant-scoped composite would be better. Also `name_search` (`:50-54`) has no collation pinned (compare to write-side at `2025-01-21-000001_CreateCookiesTable.php:63`) — case-insensitive LIKE will depend on DB default.

**M7. Decimal column `price` is `DECIMAL(10,2)` but `CookiePrice` is a money value object with minor units.**
`2025-01-21-000001_CreateCookiesTable.php:69-73` and `BusinessMetricsLogging.php:67-78`. The repo persists `$cookie->getPrice()->toDecimalString()` (`CookieRepository.php:348`) and rehydrates with `CookiePrice::fromString((string) $data['price'])` (`:310`). Currency is never persisted on the write side — the read model has `price_currency` (`2026-05-20-200000_CreateCookieReadModelTable.php:65-70`) but the write side does not. If `CookiePrice` ever supports non-USD, write→read round-trips lose currency information silently.

**M8. `CookieModel::existsByName` uses `LOWER(name) = ?` which prevents index usage on MySQL.**
`CookieModel.php:96`. With the `utf8mb4_unicode_ci` collation already pinned on the column (`2025-01-21-000001_CreateCookiesTable.php:63`), case-insensitive comparison is automatic without the LOWER() wrapper. Wrapping kills the unique index and forces a full table scan on every existence check. Compounds with the unique-index gap from C1/C2: not only is the check global, it's slow.

### LOW

**L1. `raiseConcurrentModification` is `: never` but the function flow already throws unconditionally — the static analyser will be fine, but unit-testing the negative path is awkward.**
`CookieRepository.php:398-414`. Minor; mostly fine.

**L2. `findByIdWithTrashed` ignores `trackPopularCookie` — inconsistent with `findById`.**
`CookieRepository.php:286-296`. Either both should track or neither.

**L3. Migration declares `tenant_id` nullable.**
`2025-01-21-000001_CreateCookiesTable.php:55`. Docblock at `:13-16` says "Nullable while the template is single-tenant; required by the time a real tenant resolver is wired in." This is fine as a comment but there is no follow-up enforcement (no test asserts non-null after migration runs in multi-tenant mode, no flag in config). Cloning this template into a real ERP entity without re-reading the migration docblock will produce nullable tenant ids by default and reproduce C1.

**L4. Migration uses `'collation' => 'utf8mb4_unicode_ci'` on the `name` column only.**
`2025-01-21-000001_CreateCookiesTable.php:63`. Other VARCHAR/TEXT columns (`description`) and the rest of the table inherit the DB default (`utf8mb4_general_ci` per `Config/Database.php:38`). Mixed collations on the same table can produce surprising sort/compare behaviour when joining tables that follow the same pattern. The Forge `addField` call at `:44-124` has no `tableOptions` to pin `CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` at the table level.

**L5. `delete()` ignores soft-delete races.**
`CookieRepository.php:246-261`. Looks up via `findById` (which respects soft-delete filter), then calls `$model->delete($id)`. If another request soft-deletes between the find and the delete, the second delete is a no-op but `findById` already returned a non-null cookie (or worse, returns `null` and we return `false` — masking that the row exists but is already deleted). No `version` check.

**L6. `updateWithOptimisticLock()` does not include `updated_by` in the payload.**
`CookieRepository.php:377-388`. Sets `version` and `updated_at` but the audit `updated_by` column is never written. See H1.

**L7. `Migration uses `unsigned` on `INT(11)` constraints but `DECIMAL(10,2)` has no unsigned flag.**
`2025-01-21-000001_CreateCookiesTable.php:69-73`. Allows negative prices at DB level. `CookiePrice` enforces positivity in the VO, but defence-in-depth would set `'unsigned' => true` on the column too.

**L8. `setRepositories` accepts `array<string, object>` with no validation.**
`CookieServiceProvider.php:222-227`. Any object passes; type checking is deferred to first use in `registerCommands` / `registerQueries`. Fine, but the keys-required vs keys-passed contract is invisible.

---

## Template-cloning risks

Every operational ERP entity that follows this template will inherit:

1. **A tenant column that is never populated and never scoped** (C1). Every cloned entity will allow cross-tenant duplicates on day one of multi-tenancy, with no compile-time signal that anything is wrong. This is the #1 risk because the schema *looks* tenant-aware.
2. **A composite unique index that doesn't fire** for new rows because `NULL` ≠ `NULL` in MySQL UNIQUE indexes (C2). The repo's duplicate-key catch block at `CookieRepository.php:105` is dead code in MySQL prod until tenant_id and a sentinel-not-null deleted_at strategy are sorted.
3. **Optimistic locking that throws false positives on idempotent updates** (C4), because `affectedRows()` ≠ `matchedRows()` on MySQL. Cloned entities inherit this bug verbatim.
4. **No `created_by` / `updated_by` / `deleted_by` writes** (H1, L6). The audit columns are schema decoration only. Cloned entities silently have NULL audit trails.
5. **Restore bypasses optimistic locking and timestamps** (C3) — every cloned entity inherits a restore path that can lose writes.
6. **Builder state leakage between calls on the same repository instance** (H4). Cloned entities inherit this because they will use the same `$this->model->builder()` pattern.
7. **Hardcoded business thresholds inside reusable traits** (M5 — stock=10, price-change=10%, popularity=100). Every cloned entity gets the same opaque numbers; either domain-specific tuning is impossible or the traits are not actually reusable.
8. **CI4 model validation duplicating Value Object validation** (M3). Cloned entities will gain a second source of truth that drifts.
9. **`isDuplicateKey()` substring-matching English MySQL error messages** (H3). Any non-English deployment or PostgreSQL move makes every cloned entity's duplicate handling fail open.
10. **Event-dispatch fallback that silently drops aggregate events when dispatcher is null** (H6). Cloned entities inherit the same null-tolerant ctor and will silently lose `*StockChanged`-equivalent events on misconfiguration.
11. **Provider registration is hand-maintained** (H5). Cookie domain is already missing a `CookieRestoredEventHandler` and the read-model projection wire-up. Cloning this exact pattern means every new domain will start out with similar gaps unless a registration-completeness test is added.
12. **No transaction wrapping save + event dispatch** (H7). Cloned entities inherit a save path that can leave the read model permanently desynced from the write side.
13. **Read-model migration ignores tenant scoping on indexes** (M6). Every cloned read model inherits the same index design and the same problem.

---

## Verdict

**FAIL — do NOT clone this template into other ERP entities until at minimum C1, C2, C3, C4, and H5 are fixed.**

The Cookie domain is a clean, well-typed reference for CQRS *patterns*, but as a persistence + DI surface for an ERP it is missing the load-bearing parts:

- Tenant scoping is a schema-only fiction — runtime ignores it (C1).
- The composite UNIQUE designed to enforce per-tenant uniqueness will never fire in MySQL prod because `tenant_id` and `deleted_at` are nullable and never populated (C2). Tests pass because SQLite is forgiving.
- Optimistic locking uses `affectedRows()` and will throw false-positive concurrent-modification exceptions on idempotent updates in MySQL (C4).
- The restore path bypasses version, audit, and timestamps (C3).
- Provider registration is incomplete — `CookieRestoredEvent` has no handler and the projection is not wired (H5), so the read model is silently stale.
- Duplicate-key translation (H3) only works for English MySQL.
- Audit columns (`created_by`, `updated_by`, `deleted_by`) are write-once-via-migration-only — never populated by the repo (H1, L6).

**Required fixes before template adoption:**
1. Introduce a `TenantContext` service injected into the repository; scope every read/write/exists query by `tenant_id`. Make `tenant_id` `NOT NULL` once the resolver lands.
2. Either (a) keep the composite UNIQUE and stop populating `deleted_at` with NULL (use `'9999-12-31'` sentinel), or (b) replace it with a functional/conditional index where supported, or (c) drop the index and rely on application-layer plus a partial unique index `(tenant_id, name) WHERE deleted_at IS NULL` on PostgreSQL.
3. Replace `affectedRows()` lock check with `MYSQLI_CLIENT_FOUND_ROWS` or post-update SELECT.
4. Route `restore()` through the same optimistic-locking + timestamp + audit path as `save()`.
5. Translate duplicate-key by SQLSTATE / `getCode()`, not by message substring.
6. Add a `CookieRestoredEventHandler` and register `CookieReadModelProjection` in `registerEvents()`. Add a domain-test that asserts every event has at least one subscriber.
7. Either wrap save+dispatch in a transaction, or write events to an outbox in the same transaction and let the relay deliver them.
8. Pull domain-specific thresholds out of the reusable traits (config or per-domain constants).
9. Drop the CI4 model `validationRules` — validation is the Value Object's job.

The pattern direction (port interface, AggregateRoot trait, separation of model/repo/entity) is correct. The implementation has too many production-grade gaps to be safely templated.
