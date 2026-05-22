# 07 — DTOs & Read Models

**Slice:** CookieDTO, CookieView, PriceFormatter — boundary objects
**Reviewer:** cqrs-specialist
**Date:** 2026-05-22
**Source files reviewed:** 8 (DTO, View, Formatter, 3 query handlers, query repository, 2 views, controller spot-checks)

## TL;DR

The Cookie domain ships **two parallel read-side DTOs** (`CookieDTO`, `CookieView`) with overlapping responsibilities, inconsistent field sets, and a hardwired coupling to the write-side `Cookie` entity. The actual runtime read path uses `CookieDTO`; `CookieView` is **dead code outside its own unit test** (confirmed: `grep CookieView` across `app/` returns only `ReadModels/CookieView.php`). A cloner running `sed s/Cookie/Foo/g` will inherit two competing patterns, an obsolete view class, and a stateless `PriceFormatter` service that the production code path bypasses entirely (`CookieQueryRepository::formatPrice()` calls `CookiePrice::format()` directly, which is itself `@deprecated` in favor of `PriceFormatter`). A round-1 CRITICAL ("handlers return entities, not DTOs") has been partially resolved — handlers now return `CookieDTO` — but the resolution left `CookieView` orphaned and the price-formatting architecture self-contradictory.

## Verdict

**NOT-READY**

## Findings

### F1 — CRITICAL — Two competing read-DTOs; `CookieView` is dead code

- **Location:** `app/Domain/Cookie/DTOs/CookieDTO.php` and `app/Domain/Cookie/ReadModels/CookieView.php`
- **Observation:** Production code path (`CookieController::index/show/edit` → query handlers → `CookieQueryRepository`) returns and consumes `CookieDTO` exclusively. `CookieView` is only referenced by its own unit test and its own file. The two classes overlap on `id, name, description, price, stock, isActive, createdAt, updatedAt`; they diverge on `formattedPrice` (DTO has it, View doesn't), `version/deletedAt/isDeleted/isAvailable/extra` (View has them, DTO doesn't), and `isOutOfStock()` behavior (DTO has it, View doesn't). The View's own docblock declares it "the read-model DTO" — which makes the silent presence of a *second* read DTO actively misleading to a cloner.
- **Why this is a template defect:** Cloning produces both `FooDTO` and `FooView`; the developer must guess which is canonical. Round-1 finding 03 already flagged "CookieView is dead code"; round-2 r03 confirmed it. Both the round-2 r14 and r12 plans referenced wiring it up — instead the team wired `CookieDTO` and left `CookieView` in place.
- **Suggested fix:** Pick one. Either (a) delete `CookieView.php` and its test, fold `version/deletedAt/isDeleted/isAvailable` and a `summary()` factory into `CookieDTO`; or (b) delete `CookieDTO.php`, port `formattedPrice`/`isOutOfStock` onto `CookieView`, and refit handlers + repo to return `CookieView`. Do not ship both.

### F2 — CRITICAL — `PriceFormatter` service is bypassed by every production caller

- **Location:** `app/Domain/Cookie/Services/PriceFormatter.php`, `app/Domain/Cookie/Repositories/CookieQueryRepository.php:197`, `app/Domain/Cookie/DTOs/CookieDTO.php:44`, `app/Domain/Cookie/ValueObjects/CookiePrice.php:120-125`
- **Observation:** `CookiePrice::format()` is marked `@deprecated` with `"Use \App\Domain\Cookie\Services\PriceFormatter::format()"`. Yet both real callers — `CookieDTO::fromEntity()` (line 44) and `CookieQueryRepository::formatPrice()` (line 197) — call the deprecated `$cookie->getPrice()->format()` / `CookiePrice::fromString(...)->format()`. `PriceFormatter::format()` is invoked nowhere in `app/`. The deprecation arrow points at a service no one uses.
- **Why this is a template defect:** A cloner generates `FooPriceFormatter` (or whatever the rename yields) and an equivalent `@deprecated` notice on `FooPrice::format()` — and neither side wires it up. The "extracted to keep the VO focused on monetary invariants" docblock claim is contradicted by the fact that the VO still does the formatting.
- **Suggested fix:** Either (a) update `CookieDTO::fromEntity()` and `CookieQueryRepository::formatPrice()` to call `PriceFormatter::format($price)` and remove the `@deprecated` from `CookiePrice::format()` once it's truly unused; or (b) delete `PriceFormatter.php` and drop the deprecation notice — the VO doing its own formatting is fine for a one-currency domain.

### F3 — HIGH — `PriceFormatter` is not stateless-by-design and not locale-aware

- **Location:** `app/Domain/Cookie/Services/PriceFormatter.php:32-38`
- **Observation:** The class is `final` (not `final readonly`), has only `public static function format()`, and accepts a `?string $currencySymbol` that is prepended raw to the decimal string (e.g. `"R$2.99"` with no thousands separator, no decimal-mark localization, no spacing). It uses no `IntlNumberFormatter`, no locale, no currency code parameter. "Formats… for human-readable display" is overpromised; what it actually does is "concatenates a symbol with `$money->toDecimalString()`".
- **Why this is a template defect:** Cloners will assume `PriceFormatter` is a locale-aware boundary helper and ship it to production, then discover Brazilian/European customers see `R$1234.5` instead of `R$ 1.234,50`. If this is the canonical formatter pattern, it must either pull `IntlNumberFormatter` in or honestly document itself as "symbol-prefix only".
- **Suggested fix:** Either upgrade to `IntlNumberFormatter::create($locale, NumberFormatter::CURRENCY)->formatCurrency($amount, $currencyCode)` or rename to `PriceSymbolPrefixer` and shrink the docblock to match.

### F4 — HIGH — `CookieDTO` exposes behavior (`isOutOfStock()`), violating its own docblock

- **Location:** `app/Domain/Cookie/DTOs/CookieDTO.php:55-58`
- **Observation:** Class docblock says "Prevents domain entities from leaking into the presentation layer." But `isOutOfStock(): bool` is a *domain predicate* on the DTO. The two view templates (`cookies/show.php:47`, `cookies/index.php:52`) invoke it. This blurs the DTO/entity boundary the audit's own scope expects to be clean: a DTO must "not carry behavior".
- **Why this is a template defect:** A cloner sees the precedent and adds `isLowStock()`, `isExpensive()`, `isOnSale()` to `FooDTO`. Quickly the DTO becomes a parallel entity, defeating the decoupling claim. If the view needs `isOutOfStock`, it should be a precomputed boolean field on the DTO (`public bool $outOfStock`) populated at `fromEntity()` time, mirroring how `CookieView` does `isDeleted`/`isAvailable`.
- **Suggested fix:** Replace the method with a precomputed `public bool $outOfStock` field; or move the predicate to a `CookieViewHelper` if it must be derived; do not let DTOs grow methods.

### F5 — HIGH — `CookieDTO::id` is nullable; serialization produces ambiguous payloads

- **Location:** `app/Domain/Cookie/DTOs/CookieDTO.php:22`
- **Observation:** `public ?int $id` is nullable. `fromEntity()` passes `$cookie->getId()` which may be `null` for an unpersisted entity. `CookieQueryRepository::toDto()` always casts `(int) $row['id']`, so the read path will never produce a null id — but the type contract permits it, meaning any controller doing `"/cookies/{$dto->id}"` (e.g. `cookies/show.php:8,28,83,86`) may emit `/cookies/` (empty) without a static-analysis warning. `CookieView` solved this differently (`$cookie->getId() ?? 0`) which round-1 flagged as silently producing id `0` — `CookieDTO` swung the other way and made null legal in the type.
- **Why this is a template defect:** The view templates render `$cookie->id` directly into URLs and table cells. A cloner can ship a DTO whose `id` is `null` and only discover it at runtime. Read-side DTOs reconstituted from a row always have an id; declaring it nullable creates a contract that doesn't match reality.
- **Suggested fix:** Make `id` non-nullable (`public int $id`). If a "pre-persistence preview" use case really exists, give it its own `UnpersistedCookieDTO` or use `int|null` only at the construction-site for that path.

### F6 — HIGH — `CookieDTO` and `CookieView` represent `price` differently; no JSON contract

- **Location:** `CookieDTO.php:25-26`, `CookieView.php:40,101`
- **Observation:** `CookieDTO` exposes `price` (string) **and** `formattedPrice` (string). `CookieView` exposes only `price` (string) and emits it as the `price` key in `toArray()`. Neither implements `JsonSerializable`. `CookieDTO` has no `toArray()` at all — so JSON serialization of `CookieDTO` falls back to public-property reflection, producing keys `formattedPrice` (camelCase) and `isActive`. `CookieView::toArray()` deliberately emits `is_active`, `is_deleted`, `is_available` (snake_case). The two surfaces disagree on case conventions, on whether to include a formatted price, and on key ordering. Dates are returned as untyped strings (whatever MySQL `DATETIME` cast produces — *not* ISO-8601, not RFC-3339, no timezone marker).
- **Why this is a template defect:** A cloner copying the DTO ships camelCase JSON; copying the View ships snake_case JSON; copying both ships both. There is no shared serialization contract or `ApiResponse`-friendly base. The View's docblock mentions "the new ApiResponse envelope wraps that as `data`" but no such envelope is referenced.
- **Suggested fix:** Introduce `app/Shared/DTOs/ReadDTOInterface` with required `toArray(): array` + `jsonSerialize(): array`; normalize on snake_case at the boundary; convert date strings via a shared `DateTimeValue::toIso8601()` helper so timezone is deterministic.

### F7 — HIGH — `CookieView` couples a "read model" to the write-side `Cookie` entity

- **Location:** `app/Domain/Cookie/ReadModels/CookieView.php:7,56,77`
- **Observation:** Both factories take `Cookie $cookie` (the aggregate). No `fromRow(array $row)` or `fromDto(CookieDTO $dto)` factory exists. The class's own docblock says "Serialisable without leaking entity internals" — but the constructor *takes* the entity, defeating the decoupling at the type level. Round-1 finding 03 raised this exactly; it remains unaddressed.
- **Why this is a template defect:** Cloners cannot use `FooView` from a read-side repository that returns rows. Either the view is unusable in the read path, or the repo must reconstitute the entity just to throw it away — exactly the cost CQRS read-DTOs exist to avoid.
- **Suggested fix:** Add `CookieView::fromDto(CookieDTO $dto)` and `CookieView::fromRow(array $row)`; mark the `Cookie`-accepting factory as `@internal` or remove it.

### F8 — MEDIUM — `CookieView` cannot faithfully represent soft-deleted/restored state from the read path

- **Location:** `CookieView.php:46-47,68-70`, `CookieDTO.php:22-30`, `CookieQueryRepository::toDto():173-189`
- **Observation:** `CookieView` carries `?string $deletedAt`, `bool $isDeleted`, `bool $isAvailable` — but the production read DTO (`CookieDTO`) carries none of these. The repository's `findById`/`findAll`/`findPaginated` all add `where('deleted_at', null)`, so soft-deleted rows are invisible regardless. A "show restored" or "show trashed" admin UI is impossible without adding a flag to the query, a column to the DTO, and a field at the view layer. The View claims to model it; the DTO actually used does not.
- **Why this is a template defect:** Cloners inherit a half-built soft-delete story. The schema, entity (`isDeleted()`), and `CookieView` all model restore/delete; the DTO and query path silently filter it out. A `FooRestoreController` cannot render restored rows without rewriting the read stack.
- **Suggested fix:** If the system supports restore (it does — `RestoreCookieCommand` exists), the read DTO must carry `?string $deletedAt`/`bool $isDeleted` and the query repo must accept an `includeTrashed` flag. Mirror the choice in `CookieDTO` so both surfaces agree.

### F9 — MEDIUM — `CookieView::$extra` is dead state; `toArray()` silently drops it

- **Location:** `CookieView.php:49,98-114`
- **Observation:** Constructor accepts `array $extra = []`; `toArray()` does not include it in the returned payload. Round-1 finding 03 already flagged this; it has not been fixed in r3.
- **Why this is a template defect:** Cloners pass `extra: ['tenant_id' => 7]` expecting it in the JSON, never see it, and waste hours debugging. Misleading dead state should not survive in a reference template.
- **Suggested fix:** Either include `...$this->extra` in `toArray()` or remove the parameter.

### F10 — MEDIUM — Naming inconsistency: `DTOs/CookieDTO` vs `ReadModels/CookieView`

- **Location:** Folder layout `app/Domain/Cookie/DTOs/` and `app/Domain/Cookie/ReadModels/`
- **Observation:** Two folders, two naming systems. `DTO` suffix vs `View` suffix. `Services/PriceFormatter` lives in a third folder. No `app/Shared/DTOs/` or `app/Shared/ReadModels/` base exists (confirmed: `app/Shared/` does not exist; the closest is `app/Domain/Shared/` which has no DTO/View directories). Round-2 r14 file-coverage audit flagged "ReadModels vs DTOs" naming drift; it remains.
- **Why this is a template defect:** `sed s/Cookie/Foo/g` produces `app/Domain/Foo/DTOs/FooDTO.php` *and* `app/Domain/Foo/ReadModels/FooView.php`. There is no convention to enforce; the cloner duplicates the same drift.
- **Suggested fix:** Settle on one: either `app/Domain/Foo/ReadModels/FooReadModel.php` everywhere, or `app/Domain/Foo/DTOs/FooReadDTO.php`. Document in `cqrs-architecture` skill.

### F11 — MEDIUM — Asymmetric factories: `CookieDTO::fromEntity()` vs `CookieView::detail()/summary()`

- **Location:** `CookieDTO.php:37`, `CookieView.php:56,77,122`
- **Observation:** `CookieDTO` has one factory (`fromEntity`). `CookieView` has three (`detail`, `summary`, `summarise`). Neither has `fromRow`. The repo (`CookieQueryRepository::toDto()`) builds the DTO manually inline rather than via a factory. A cloner who wants "summary" semantics has to copy `CookieView::summary` and adapt it — but the actual data source is the DTO/row, not the entity, so the example is structurally wrong.
- **Why this is a template defect:** Three different factory styles in 90 lines of code; no symmetry to copy.
- **Suggested fix:** Standardize on `static fromEntity($e): self`, `static fromRow(array $row): self`, optional `static summary(...): self`. Use the same names on both classes (or on the merged class).

### F12 — LOW — `CookieView` has `private __construct()` but exposes mutable `public` properties

- **Location:** `CookieView.php:36-51`
- **Observation:** Class is `final readonly`, constructor is `private`, but the readonly modifier already prevents external mutation. The private constructor enforces "go through a named factory" — which is fine — but the public properties paired with `readonly class` mean any caller can read them via `->id`, `->name`, etc. Inconsistency with `CookieDTO`, which has a `public` constructor. Not a bug, just a divergence the cloner has to choose between.
- **Why this is a template defect:** Two valid patterns in the same domain. Pick one.
- **Suggested fix:** Pick `public __construct` + named-static-factories as a convention (it's what `CookieDTO` does and what the AggregateRoot/Money/Currency value objects do).

### F13 — LOW — No defensive copies needed (none used; safe)

- **Location:** `CookieView::summarise():122-125`, `CookieQueryRepository::findAll():101`
- **Observation:** Both arrays of DTOs are returned via `array_map` on a freshly-built list; no shared mutable state. `readonly class` makes element-level defensive copies unnecessary. Noted positively — but worth documenting in the skill so cloners don't add unnecessary `clone` loops.

### F14 — LOW — Hard-coded Cookie-specific bits in the formatter (none found; safe)

- **Location:** `PriceFormatter.php`
- **Observation:** Despite the name, the only Cookie coupling is the `CookiePrice` parameter type. Logic would generalize if `CookiePrice` were a shared `Money`. Since the project already has `App\Domain\Shared\ValueObjects\Money`, the formatter could be lifted to `app/Domain/Shared/Services/MoneyFormatter` and used by every domain.
- **Why this is a template defect:** Cloning produces `OrderPriceFormatter`, `InvoicePriceFormatter`, etc. — each a copy of the same 8-line static method. A shared formatter is the obvious factoring.
- **Suggested fix:** Move to `app/Domain/Shared/Services/MoneyFormatter::format(Money $m, ?string $symbol = null)`; have `PriceFormatter` (if kept) delegate.

## What is correct / praiseworthy

- Both classes are `final readonly` with typed properties — strict-types compliant, PHP 8.3 idiomatic.
- Both have meaningful docblocks explaining *why* the class exists (especially `CookieView`'s rationale block).
- `CookieDTO` cleanly separates write-side (entity) from read-side (DTO) at the handler boundary — round-1's CRITICAL ("handlers return entities") **is resolved** for handlers.
- `CookieQueryRepository::formatPrice()` correctly swallows `Throwable` on malformed rows rather than 500-ing — read paths shouldn't crash on bad data.
- `CookieView::summary()` factory pattern (lean payload for list rows) is a good idea worth keeping if the class survives consolidation.
- `PriceFormatter` is stateless and free of side effects — easy to test, easy to mock.

## Top 3 fixes before cloning

1. **Pick one read-side DTO.** Either delete `CookieView.php` or delete `CookieDTO.php`; do not ship both. The cloner cannot guess. If `CookieView` wins, port `formattedPrice`/`isOutOfStock`-as-field onto it, add `fromRow()`/`fromDto()` factories, and switch handlers + repo + views.
2. **Resolve the `PriceFormatter` vs `CookiePrice::format()` contradiction.** The deprecation arrow points at a service zero production code calls. Either route `CookieDTO::fromEntity` and `CookieQueryRepository::formatPrice` through `PriceFormatter::format()`, or delete `PriceFormatter` and remove the `@deprecated` tag from `CookiePrice::format()`.
3. **Establish a shared serialization contract.** Introduce `app/Domain/Shared/DTOs/ReadDTOInterface` (or similar) with `toArray(): array<string, scalar|null>` and a documented key-case (snake_case recommended for JSON boundaries) and an ISO-8601 date convention. Lift `MoneyFormatter` and the date-to-ISO helper out of the Cookie domain so every cloned domain produces consistent JSON.

---

**Severity counts:** CRITICAL: 2 | HIGH: 5 | MEDIUM: 4 | LOW: 3

**Top finding:** F1 (CRITICAL) — `CookieView` is dead code; `CookieDTO` is the real read-side class. Two competing DTOs in one reference template will be duplicated on every clone.
