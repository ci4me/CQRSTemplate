# Review (ddd-specialist) — v2 Remediation Plan

## Verdict
APPROVED-WITH-CHANGES

The plan correctly identifies the structural DDD issues across slices 01,
02 and 06 and allocates them to coherent epics (E04, E06, E07, E09, E11).
Foundation-first sequencing is sound. However, four DDD-specific items
need tightening before sign-off and one finding is mis-allocated.

## Strengths

- **Lifecycle epic (E07) is correctly grouped, not fragmented.** Slice
  01's F1, F2, F3, F6, F7, F9, F11 land in one epic alongside the two
  new `Activated`/`Deactivated` events, and the repo's `delete()`/
  `restore()` is rewritten to call entity mutators. This honours the
  "entity-as-consistency-boundary" rule.
- **E06 hydrator-key + `AggregateRootInterface`** is the right
  mechanical answer to slice 01 F5 (public-`@internal`). Passing a
  `AggregateHydrator $key` instance to `assignId`/`bumpVersion` makes
  the invariant engine-enforced, not docblock-enforced. The
  `version >= 1` guard on `reconstitute()` (slice 01 F4) is correctly
  paired here, not deferred to PHP 8.4.
- **E04 `AbstractDomainEvent`** preserves aggregate identity correctly:
  `eventId` (UUIDv7) + `occurredAt` + `actorId` are envelope-level;
  domain payload (aggregate id, prev/new state) stays on the concrete
  event class. The plan also tightens
  `CookieStockChangedEvent::$cookieId` to non-nullable, closing slice
  01 F7's `(int) $this->id` cast trap.
- **E11 trusted-reconstitution path** (`fromTrusted` factories on
  `CookieName`/`CookiePrice`) lands in the same epic as the repo
  hygiene (LIKE escape, single-statement delete, restore-version-bump).
  Correct allocation: this is a port-side trust-boundary concern, not
  a VO concern.
- **Repository ports stay CI4-free.** E11 keeps `CookieRepositoryInterface`
  / `CookieQueryRepositoryInterface` under `Domain/Cookie/Ports/`; the
  only infra type touched (`BaseConnection`) is dropped to plain
  (slice 06 F15) rather than promoted into the port.
- **E09 multi-currency** correctly recomputes bounds from
  `$currency->decimals` instead of hard-coding USD-cents semantics
  (slice 02 F1), and makes `Currency` a required factory parameter
  (slice 02 F2). The schema change (`price_minor` BIGINT + `price_currency`
  CHAR(3)) is destructive and sequenced after E03 / E01 — correct.

## Required changes

1. **E07 — entity must own `softDelete()` / `restore()`; repo must NOT
   write `deleted_at` directly.** The current epic description says
   *"`CookieRepository::delete` calls `$cookie->softDelete()`; `restore`
   calls `$cookie->restore()` (drain via `pullEvents()`)"* — good — but
   the parallel E11 still says *"single-statement `delete()`" and
   "`restore()` bumps version + checks `affectedRows`"*. These two
   descriptions conflict on **who owns the state transition**. DDD lens
   requires the entity to mutate `deletedAt` / clear it via the new
   methods, and the repo to be a pure persister. Reword E11 to: "repo
   persists the entity's mutated state; the conditional UPDATE is the
   *persistence shape*, not the lifecycle decision." Otherwise a cloner
   reading E11 in isolation will replicate the repo-owned soft-delete
   anti-pattern.

2. **E04 — `AbstractDomainEvent` must expose `aggregateId` symmetry.**
   The plan currently lists `eventId` + `occurredAt` + `actorId` on the
   base. Slice 01 F11 and the cross-cutting T1 note that the five Cookie
   events also disagree on aggregate-identity field name (`$cookieId`
   vs implicit). Add `public readonly string $aggregateType` (e.g.
   `'Cookie'`) + `public readonly int|string $aggregateId` to the base.
   Otherwise the outbox cannot correlate events to aggregates without
   per-event reflection, and the dedupe-by-`event_uuid` (E12) is the
   only correlation surface.

3. **E09 — `CookieName` equality must collapse to ONE semantics; the
   choice belongs in this epic, not E17.** Slice 02 F3 + F7 is currently
   only mentioned in E09's "rename / dedup `equalsIgnoreCase`" bullet
   but the resolution is hand-waved. DDD demands: (a) normalize on
   construction (case-fold via `mb_convert_case`), (b) delete
   `equalsIgnoreCase()`, (c) document the choice in the entity
   docblock. The repo's `existsByName` (slice 06 F1) and the schema's
   `utf8mb4_unicode_ci` collation must agree with the VO's choice. As
   written, E09 hits the schema and E11/E17 hit the methods separately
   — risk: drift between layers.

4. **E06 — add explicit guard: `bumpVersion()` must reject calls when
   `$id === null`.** Slice 01 F4 implies this; the current E06 bullet
   only requires `version >= 1` in `reconstitute()` and the hydrator
   key on `bumpVersion`. The hydrator key gates *who* can call it; it
   does not gate *when*. A repo holding a key can still bump a transient
   entity. Add the `assertPersisted('bumpVersion')` precondition.

## Missing items

- **Slice 01 F8 (CookieAccessors `@property` phantom-properties trait)
  is not allocated.** The plan never touches `CookieAccessors.php`
  except to update its `@property` block in E07 to reflect
  `getStock(): CookieStock`. The trait-with-phantom-properties pattern
  is itself the defect (rename drift is invisible at runtime). DDD lens:
  either inline the 10 trivial getters into `Cookie.php` (entity is
  already within the 200-line budget per slice 01 TL;DR) or convert to a
  sealed interface. Recommend allocating to E07 or a new dedicated
  bullet in E17.

- **Slice 01 F9 (stringly-typed `reason` in `changeStock`).** The
  plan allocates `StockChangeReason` enum to E16 (PHP 8.4 phase).
  This is mis-allocated: native enums are PHP 8.1, not 8.4. DDD lens
  demands this lands with E07 (entity lifecycle) so that the entity's
  public API speaks the ubiquitous language (`SALE`, `RESTOCK`,
  `RETURN`, `ADJUSTMENT`) from day one. Move to E07 or E09.

- **Slice 02 F4 (CookieStock overflow guard) and F6 (`getValue(): float`
  removal + `JsonSerializable`) are allocated to E09**, which is fine,
  but **F6's `JsonSerializable` on `CookieName` and `CookieStock`** is
  not mentioned. E09 only adds it to `CookiePrice`. Add the two
  remaining VOs explicitly.

- **Slice 06 F16 (CookieModel `$validationRules` duplicates VO
  invariants).** Plan does not allocate this anywhere. DDD lens: model
  rules should be DB-shape safety only (types/required); business
  ranges live in VOs. Allocate to E11 or E18.

## Suggested re-orderings

- **E07 before E04 logically inverted in the dependency graph.** E07
  "Depends on: E04, E06" is listed, but E07 introduces two new event
  classes (`CookieActivatedEvent`, `CookieDeactivatedEvent`) that must
  extend `AbstractDomainEvent`. This is correct — keep as is. However,
  the graph block in REMEDIATION-PLAN.md line 1003–1008 lists E07 and
  E09 in parallel under Phase 2; per the dependency notes E09 has a
  soft dep on E07 (seeder uses `CreateCookieCommand`). Tighten the
  graph to show E07 → E09 explicitly so a contributor running the
  parallel plan (line 1023–1028) does not pick E07 + E09 in the same
  week.

- **E11 (repository hygiene) should land BEFORE E10 (read-side DTO
  consolidation), not in parallel.** E10 currently depends on both
  E09 and E11; the plan text confirms this, but the graph implies they
  can run in parallel. The trusted-reconstitution path from E11 must
  exist before E10's `CookieDTO::fromRow()` is written, otherwise the
  DTO factory will re-implement validation skipping.

## Net new epic recommendations

None. The 18-epic structure is sufficient if the four required changes
above are folded in. Adding another epic would fragment the
lifecycle/aggregate work that E06+E07 already group correctly.

---

**Net DDD verdict:** the plan respects the entity-as-consistency-boundary
rule, preserves event aggregate-identity (with the F11 aggregateId
addition), keeps repository ports CI4-free, and allocates trusted
reconstitution to the right epic. With the four required changes above,
this DDD specialist would not block the PRs.
