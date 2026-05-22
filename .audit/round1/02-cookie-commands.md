# 02 — Cookie commands + handlers

## Files audited

- `app/Domain/Cookie/Commands/CreateCookie/CreateCookieCommand.php`
- `app/Domain/Cookie/Commands/CreateCookie/CreateCookieHandler.php`
- `app/Domain/Cookie/Commands/UpdateCookie/UpdateCookieCommand.php`
- `app/Domain/Cookie/Commands/UpdateCookie/UpdateCookieHandler.php`
- `app/Domain/Cookie/Commands/DeleteCookie/DeleteCookieCommand.php`
- `app/Domain/Cookie/Commands/DeleteCookie/DeleteCookieHandler.php`
- `app/Domain/Cookie/Commands/RestoreCookie/RestoreCookieCommand.php`
- `app/Domain/Cookie/Commands/RestoreCookie/RestoreCookieHandler.php`
- Context: `app/Domain/Cookie/Ports/CookieRepositoryInterface.php`, `app/Infrastructure/Bus/CommandBus.php`, `app/Domain/Cookie/Entities/Cookie.php`, `app/Domain/Shared/AggregateRoot.php`, `app/Infrastructure/Bus/Middleware/{TransactionMiddleware,AuditMiddleware}.php`, `app/Models/Cookie/CookieRepository.php`, `app/Domain/Shared/ValueObjects/Actor.php`, all `Events/Cookie*Event.php`.

## Findings

### CRITICAL

- **Optimistic locking is implemented in entity + repo but NEVER exercised by `UpdateCookieHandler`.**
  - `app/Domain/Cookie/Entities/Cookie.php:63-67,142,149,160-163,181-184` exposes `version` + `getVersion()` + `bumpVersion()`.
  - `app/Domain/Cookie/Commands/UpdateCookie/UpdateCookieCommand.php:27-34` does not carry a `version` / `expectedVersion` field.
  - `app/Domain/Cookie/Commands/UpdateCookie/UpdateCookieHandler.php:72-111` re-loads the entity inside the handler and never checks the caller's expected version. The classic "load, mutate, save" with no version compare means last-write-wins for any concurrent UPDATE arriving over a stale form — exactly the race the version column was added to prevent. The DB-level `WHERE version = $version` will then only ever compare the freshly-loaded version against itself.
  - Template-cloning risk is severe: every cloned domain will inherit the same dead code.

- **Handlers dispatch domain events outside the persistence boundary.**
  - `app/Domain/Cookie/Commands/CreateCookie/CreateCookieHandler.php:104-110`, `app/Domain/Cookie/Commands/UpdateCookie/UpdateCookieHandler.php:113-120`, `app/Domain/Cookie/Commands/DeleteCookie/DeleteCookieHandler.php:91-96`, `app/Domain/Cookie/Commands/RestoreCookie/RestoreCookieHandler.php:59-65` all call `eventDispatcher->dispatch(...)` directly with hand-built events.
  - The repository ALREADY drains entity-side events via `pullEvents()` (`app/Models/Cookie/CookieRepository.php:130-142`), and `AggregateRoot` doc (`app/Domain/Shared/AggregateRoot.php:30-39`) explicitly says the repository owns dispatch. Two competing patterns coexist: stock-change events are raised via `$this->raiseEvent(...)` inside the entity (`Cookie.php:236,259`), but the four lifecycle events (Created/Updated/Deleted/Restored) bypass the aggregate. Mixed dispatch model is a template-level bug; every cloned domain will copy the wrong half.
  - Practical fallout: `pullEvents()` is now the ONLY path that benefits from the outbox-ready hook in `EventOutboxWriter` (`app/Infrastructure/Outbox/EventOutboxWriter.php:73`). Direct `dispatch` calls in handlers will never reach the outbox once it lands.

- **`RestoreCookieHandler` is the only handler that accepts an `Actor` — and uses it inconsistently across the suite.**
  - `app/Domain/Cookie/Commands/RestoreCookie/RestoreCookieCommand.php:17-21` carries `Actor $restoredBy`.
  - `CreateCookieCommand.php:32-38`, `UpdateCookieCommand.php:27-34`, `DeleteCookieCommand.php:22-24` do NOT. `CookieUpdatedEvent` has `int $updatedBy = 0` (`Events/CookieUpdated/CookieUpdatedEvent.php:32`) and `CookieDeletedEvent` has `int $deletedBy = 0` (`Events/CookieDeleted/CookieDeletedEvent.php:27`) — both fields are always 0 because the handlers (`UpdateCookieHandler.php:114-120`, `DeleteCookieHandler.php:92-96`) never populate them.
  - `AuditMiddleware` (`app/Infrastructure/Bus/Middleware/AuditMiddleware.php:50-53`) resolves an actor independently via `ActorResolver`, so audit_log gets attribution, but the domain events permanently lose it. New domains cloned from Cookie will inherit "actor only on restore" — a security-relevant inconsistency.

### HIGH

- **No transaction visible in handlers; reliance on `TransactionMiddleware` is undocumented at the handler.**
  - `app/Infrastructure/Bus/Middleware/TransactionMiddleware.php:40-75` does wrap the handler. That is fine, BUT the handlers contain a critical-section pattern (`existsByName` check then `save`, `findById` then `update`) and none of the handler files reference the middleware contract. If a clone is wired without `TransactionMiddleware`, `CreateCookieHandler.php:84-110` becomes a TOCTOU bug (the unique-name check happens outside any transaction guarantee). The Create flow relies on the DB unique index translation at `CookieRepository.php:100-115` as a fallback — fine, but `existsByName` check (`CreateCookieHandler.php:84`) is then redundant and misleading. Pick one.

- **`CreateCookieHandler::determineErrorCode` uses `str_contains` on exception messages.**
  - `app/Domain/Cookie/Commands/CreateCookie/CreateCookieHandler.php:155-161` — string matching on `name`, `price`, `stock`. Brittle: any message rewording (localisation, wording polish) silently breaks the mapping. The `ErrorCodes` constants and constructor-side passing already work for the structured paths; the `match (true) { str_contains(...) }` block should be deleted or replaced with typed checks. Templated to every new domain.

- **`RestoreCookieHandler` throws a generic `\RuntimeException` for a domain failure.**
  - `app/Domain/Cookie/Commands/RestoreCookie/RestoreCookieHandler.php:47-49` throws `\RuntimeException` when repo returns false. Every other failure path throws `DomainException`. This breaks the rule "translate DB errors to domain errors" and will cause the controller-side mapper to fall through to a generic 500.

- **`RestoreCookieHandler` is missing the start-time + structured success log pattern the other three handlers use.**
  - `app/Domain/Cookie/Commands/RestoreCookie/RestoreCookieHandler.php:23-66` has no `$startTime`, no `duration_ms`, no `try/catch` around the flow, no correlation between failure log and the throw site. Failing handler will not emit an error log because the `try`/`catch` is absent. Compare with `DeleteCookieHandler.php:50-121`. Inconsistency in the template = inconsistency in every clone.

- **`DeleteCookieCommand` carries no actor; `DeleteCookieHandler` logs no actor.**
  - `app/Domain/Cookie/Commands/DeleteCookie/DeleteCookieCommand.php:22-24` only has `int $id`. A soft-delete is a high-value audit event. The handler relies on `AuditMiddleware`'s actor resolver, but the dispatched `CookieDeletedEvent` permanently records `deletedBy = 0`.

### MEDIUM

- **Inconsistent timer source between handlers.**
  - `CreateCookieHandler.php:67`, `UpdateCookieHandler.php:60`, `RestoreCookieHandler.php` — `microtime(true)`.
  - `DeleteCookieHandler.php:52,98` — `hrtime(true)` then `/1_000_000`.
  - Cosmetic but the template should be uniform; clones will pick whatever they last read.

- **`UpdateCookieHandler` catch block omits `exceptionClass` and `duration_ms`.**
  - `app/Domain/Cookie/Commands/UpdateCookie/UpdateCookieHandler.php:131-137` logs less context than `CreateCookieHandler.php:127-135` and `DeleteCookieHandler.php:108-117`. Same template, three different log shapes.

- **`UpdateCookieCommand` forces full-state overwrite; no partial updates supported.**
  - `app/Domain/Cookie/Commands/UpdateCookie/UpdateCookieCommand.php:27-34` requires every field. Combined with the lack of `version` (CRITICAL above), a client editing only `description` overwrites `stock`, `price`, `isActive` with whatever was in the form. For an ERP template this is hostile by default. Consider a `PatchCookieCommand` or nullable fields, or document the convention.

- **`CreateCookieHandler.handle()` and `UpdateCookieHandler.handle()` are 70+ lines.**
  - `CreateCookieHandler.php:65-139` — 75 lines. `UpdateCookieHandler.php:58-141` — 84 lines.
  - The project CLAUDE.md mandates "Max 20 lines per method". Both blow past it. `clean-code-specialist` should split out `logStart`, `logSuccess`, `logFailure`, `executeCore`. Cloning these handlers propagates a 75-line `handle()` to every new domain.

- **`RestoreCookieHandler` never asserts the command type.**
  - All other handlers receive their concrete command type because they are not registered against a base interface. But neither do the other three add an `assert($command instanceof X)` guard. Once `CommandHandlerInterface` is introduced (the project doc hints at it: `CreateCookieHandler implements CommandHandlerInterface` in CLAUDE.md), the lack of `assert` will be a real PHPStan/runtime hazard in clones.

- **No mention of tenant scoping anywhere — but `CookieRepository.php:108` references "must be unique within the tenant" in an exception message.**
  - `app/Models/Cookie/CookieRepository.php:108` says "within the tenant" yet no command/handler carries a tenant id and no SQL clause filters by it. Either kill the misleading message or wire tenant. Flagged because it will mislead the next domain author.

- **`existsByName` race window not closed by `TransactionMiddleware` alone.**
  - `CreateCookieHandler.php:84` runs the existence check; commit happens later. If `TransactionMiddleware` uses the default isolation level (READ COMMITTED on MySQL InnoDB), two concurrent commands can both pass the check. The DB unique index catch at `CookieRepository.php:105-112` saves us, so this is acceptable, but the redundant check in the handler should be removed or explicitly documented as "best-effort fast-fail; the DB is the source of truth".

### LOW

- **Doc comments lie about validation.**
  - `CreateCookieCommand.php:15-17` says "Validated by their handlers". The handler defers validation to value objects (`CookieName::fromString`, `CookiePrice::fromString`) — which is correct. Update the docblock to "Validated by value objects via the handler".

- **`CookieCreatedEvent` carries no `createdBy`.**
  - `app/Domain/Cookie/Events/CookieCreated/CookieCreatedEvent.php:40-45` — no actor field at all (Updated/Deleted have a `*By = 0` field, even if it's never populated). Inconsistent event shape across the four lifecycle events of the SAME entity.

- **`RestoreCookieEvent` uses string `restoredAt` instead of `DateTimeImmutable`.**
  - `app/Domain/Cookie/Events/CookieRestored/CookieRestoredEvent.php:14-16` — `string` is serialisation-friendly but every other timestamp in the codebase (entity-side `createdAt`, `updatedAt`, `deletedAt`) is also a string, so this is consistent; flag only because `(new \DateTimeImmutable())->format('c')` could equally be done once at the boundary.

- **Logger info-line for the "found, performing soft delete" intermediate step adds noise without value.**
  - `DeleteCookieHandler.php:81-86` logs at INFO. Two INFO entries per successful delete. In production this doubles log volume for no audit gain (the success line already carries everything). Drop or downgrade to DEBUG.

- **No PII/sensitive-data concerns in the Cookie domain itself**, but the handlers log `name`, `price`, `stock` at INFO. Fine for cookies; will be a leak the moment the template is cloned to `User` / `Customer` / `Order` (name/email/total). Recommend adding a comment at the top of each handler: "Review log fields when cloning — Cookie has no PII; your domain may."

## Template-cloning risks

The Cookie domain IS the template, so every weakness here ships to every future ERP entity:

1. **No optimistic-locking in commands.** Every cloned UpdateXHandler will silently last-write-wins. (CRITICAL)
2. **Direct event dispatch in handlers, mixed with entity-side `raiseEvent`.** Half the events bypass the outbox-ready repository hook. Future async/outbox work has to either rewrite every handler or keep two dispatch paths forever. (CRITICAL)
3. **Actor only on `RestoreCookieCommand`.** Cloned domains will either keep the "restore is special" anomaly or remove it, in which case all attribution disappears from domain events. (CRITICAL)
4. **String-matching error-code resolver.** Will be copied verbatim and quietly stop working as messages drift. (HIGH)
5. **75-line `handle()` methods.** Violates the project's own 20-line rule, sets a bad ceiling for every clone. (MEDIUM)
6. **Full-state UpdateCommand.** Every clone will require every field, making partial updates a special case nobody implements. (MEDIUM)
7. **Inconsistent log payload shape across the four handlers.** Future per-domain dashboards will have to handle three different shapes. (MEDIUM)
8. **Stale "tenant" wording in repo exception.** Will mislead the next domain author into thinking tenancy is wired. (MEDIUM)
9. **Two timer sources (`microtime` vs `hrtime`).** Cosmetic but cloners pick whichever they read first. (LOW)

## Verdict

**Not safe to clone as-is.** Two CRITICAL findings — missing optimistic-locking enforcement on update and inconsistent event-dispatch model — will silently propagate to every future ERP entity. A third CRITICAL (actor only on restore) means the audit story is broken at the event layer, even though `AuditMiddleware` papers over it at the audit_log table.

Recommended fix order before cloning:

1. Add `expectedVersion` (or `version`) to `UpdateCookieCommand` and pass through to the repo's `WHERE version = ?` clause; throw a typed `ConcurrencyException` on row-count = 0.
2. Move lifecycle event creation into the entity (`Cookie::create`, `update`, `softDelete`, `restore` should each `raiseEvent(...)`); delete `eventDispatcher->dispatch()` calls from handlers; rely on `CookieRepository::dispatchPendingEvents()`.
3. Add `Actor $actor` (or `Actor $by`) to all four commands; thread through to the events; remove the `= 0` default on `*By` fields.
4. Replace `determineErrorCode`'s string-match block with typed exception subclasses (`CookieNameValidationException extends ValidationException`, etc.).
5. Split `handle()` into ≤ 20-line private methods per CLAUDE.md.
6. Decide partial-update policy (PATCH command vs nullable fields) and document.
7. Rewrite `RestoreCookieHandler` to match the other three (start-time, try/catch, structured success log, `DomainException` not `RuntimeException`).
8. Drop the redundant `existsByName` pre-check or annotate it explicitly as a fast-fail with DB as the source of truth.
9. Remove the "within the tenant" wording at `CookieRepository.php:108` until tenancy lands.
