# Review (cqrs-specialist) — v2 Remediation Plan

## Verdict
**APPROVED-WITH-CHANGES**

The plan correctly diagnoses every theme my slices raised (T1/T2/T4/T19) and allocates 03/04/05/07/08 findings across E04/E05/E08/E10/E12/E13. Sequencing is sound: E04 (envelope) before E12 (outbox UUID dedup) before E10 (read DTO consolidation) is exactly right. The Finding-to-Epic Matrix gives me a place to grep for every F#, including the LOW/INFO entries. However, four CQRS-specific holes need to be closed before I would clear E04/E05/E08/E12 at PR review.

## Strengths
- **Event-dispatch unification (slice 05 F1).** E04 introduces `AbstractDomainEvent` with `eventId` (UUIDv7) + `occurredAt` + `actorId`; E07 moves event raising into the entity; E08 makes handlers drain via `pullEvents()`. The three-pattern split (03/F1) is correctly attributed to E04 (event) + E07 (entity) + E08 (handler). Acceptance gate `test_every_cookie_event_extends_abstract_domain_event` is the right teeth.
- **Bus-typed interfaces (slice 03 F5 / 04 F3).** E05 explicitly deletes `method_exists` duck-typing on both `CommandBus` and `QueryBus`. Acceptance gate `test_command_bus_rejects_handler_without_interface` is enforceable.
- **RestoreCookieHandler parity (slice 03 F2 — CRITICAL).** E08 calls it out by name with the right shape: rename `$cookieId` → `$id`, `DomainException` not `RuntimeException`, camelCase, `duration_ms`, mirrored failure-log shape. Acceptance gate `test_all_command_handlers_emit_identical_failure_log_shape` makes parity verifiable.
- **CookieDTO vs CookieView (slice 07 F1).** E10 is unambiguous: **delete `CookieView`**, fold fields into `CookieDTO`, add `fromRow()`, implement `JsonSerializable`. No ambiguity in either direction.
- **PriceFormatter contradiction (slice 07 F2).** E10 routes through `MoneyFormatter` in `Domain/Shared/` and removes the `@deprecated` arrow.

## Required changes (BLOCK at PR review without these)

1. **E12 idempotency story is half-built (slice 05 F5).** Plan adds `event_uuid` UNIQUE at the outbox layer (great — closes 18/F-I2), but slice 05 F5 raised **handler-side idempotency**: side-effect handlers (email, webhook, suggested in the in-source comments of every `Cookie*EventHandler`) will double-send on relay retry even with outbox dedup, because outbox dedup only stops the relay from re-pushing — it doesn't help a handler that successfully processed once but then the worker died before ACK. **Add an explicit "handler-side `ProcessedEventStore` (event_uuid + listener FQCN)" requirement to E12** or strip the "future: send email" comments from handler stubs in E04, and add a CLAUDE.md note: "side-effect handlers MUST consume an at-most-once channel keyed on `$event->eventId`". The matrix allocates 05/F5 to "E04; E12" but neither epic body specifies a handler-side guard. This is a "later" handwave dressed up as allocation.

2. **Double-dispatch removal needs an explicit acceptance gate in E07/E08.** Plan says "repository stops draining" (E07 body) and "handlers drain via `pullEvents()`" (E04 body), but no acceptance test verifies the repository does NOT also drain. Add `test_cookie_repository_does_not_dispatch_pulled_events` and `test_dispatch_count_equals_pullevents_count` to E08. Without this, the slice 03 F1 comment ("the repository ALSO drains, but a mock repo in tests won't") will quietly survive — the audit's bug is precisely a divergence between mock-and-real behavior that no test catches.

3. **PHPStan-level enforcement of `*HandlerInterface` is missing (slice 03 F5 / 04 F3).** E05 enforces the interface at **bus registration runtime**, but no PHPStan rule prevents a handler file from compiling without implementing the interface. The matrix allocates this to "E05" with no PHPStan deliverable. Add to E05: a `phpstan-rules` custom rule (or extends-check via attribute) that fails when a class in `app/Domain/*/Commands/*/` or `Queries/*/` named `*Handler` does not implement the corresponding interface. Otherwise the cloner who registers the typo'd `FoHandler` never sees the error until first dispatch.

4. **E13 provider DI overhaul still leaves `getRepository(string)` magic-string path open as fallback.** Epic body says "deprecate `setRepositories`/`getRepositories`" without committing to deletion. With both shapes co-existing, a cloner copying the Cookie provider gets a confusing choice. Either commit to **deleting** the legacy shape in E13 (preferred), or hard-fail the legacy path with `@deprecated` + `trigger_error(E_USER_DEPRECATED)`. As-written, the slice 08 F2 CRITICAL (silent undefined-index on typos) survives in the deprecated path.

## Missing items

- **05/F4 PII envelope.** Matrix allocates to "E04 (typed envelope); E12 (PiiRegistry)". Neither epic body actually describes a `CookieChangeSet` value object replacement for the loose `array<string, scalar|null>` on Updated/Deleted snapshots. Add the typed snapshot VO to E04 acceptance gates.
- **05/F8 silent zero-listener dispatch.** Allocated to E18 (doc only). For a CQRS template this should be an opt-in `dev`-env warning hook in E04, not deferred to documentation. A cloner adding a new event with no subscription will get zero feedback today and that's a meaningful CQRS footgun.
- **04/F11 cache seam.** Matrix says "E08 (hook only)" but E08 body doesn't mention `cacheKey()` / `cacheTtlSeconds()`. Either add it to `AbstractQueryHandler` in E05 or remove the matrix allocation and accept "no caching seam shipped".
- **03/F9 `expectedVersion` opt-in default-null.** Allocated to "E08 (doc)". This is too weak — slice 03 was clear that concurrency safety becoming an opt-in feature nobody opts into IS the template defect. Either make `expectedVersion` required (no default) in E08, or document **in the controller code** what the no-version semantics mean. A docblock is not a control.

## Suggested re-orderings

- **Move E12 (outbox hardening) to land BEFORE E10 (read DTO).** E10 has no dependency on E12 in the graph, but if the audit's PII concern (05/F4) is to be addressed via typed snapshots, the snapshot shape feeds the outbox payload validation. Currently both are "Phase 2 parallel"; making E12 a hard prereq for E10 surfaces snapshot-shape conflicts earlier.
- **Pull `AbstractQueryHandler` slow-query escalation forward into E05 acceptance gates** (it's there in body text, but not in the explicit gate list). Slice 04 F7 was MEDIUM, and operators losing slow-query alerts is a real prod regression risk.

## Net new epic recommendations

- **E05.5 (PHPStan rules for CQRS).** A small (~3-file) custom phpstan-rules package covering: (a) handler-implements-interface, (b) command/query DTO is `readonly`, (c) handler `handle()` parameter type matches command/query class. Without this, the bus runtime check is the only guard and cloners don't get IDE/static-analysis feedback.
- **E12.5 (Handler-side idempotency `ProcessedEventStore`).** Separate from outbox hardening because it lives in the handler layer, not the relay. ~5 files: interface + DB-backed adapter + migration + trait helper + tests. Allocated explicitly closes 05/F5.
