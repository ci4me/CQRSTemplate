# Complete File Inventory for a New Domain

When adding a new domain to this CQRS template, you create or touch
**60+ files/touchpoints** (assuming the canonical Cookie shape: 4 commands,
3 queries, 7 events, 5 value objects, 2 ports, 2 repositories).

> **Snapshot:** This inventory reflects the Cookie domain **after PRs #29-#39**
> land (Phase 0 + Phase 1 + partial Phase 2: E07, E08, E05.5, E12.5, E17).
> The `bin/docs-cookie-sync` CI guard verifies that every file in
> `app/Domain/Cookie/` appears below and that every entry below maps to a
> real Cookie file. Drift fails the build.
>
> **Pending PRs** that will trigger the next inventory refresh: E09
> (multi-currency / `Money`), E10 (`CookieView`→`CookieDTO`), E11 (repo
> hygiene), E12 (outbox UNIQUE + relay hardening), E13
> (`registerProjections()` hook), E14 (view collapse).

---

## Domain layer (`app/Domain/{Domain}/`)

### Service provider + error codes (2 files)
1. `app/Domain/{Domain}/{Domain}ServiceProvider.php`
2. `app/Domain/{Domain}/ErrorCodes.php`

### Entities (2 files)
3. `app/Domain/{Domain}/Entities/{Entity}.php` — implements
   `AggregateRootInterface` (E06).
4. `app/Domain/{Domain}/Entities/{Entity}StateAssertions.php` — extracted
   invariant guards (E07).

### Ports (2 files)
5. `app/Domain/{Domain}/Ports/{Entity}RepositoryInterface.php`
6. `app/Domain/{Domain}/Ports/{Entity}QueryRepositoryInterface.php`

### Repositories (write + read, 2 files)
7. `app/Domain/{Domain}/Repositories/{Entity}Repository.php` — write side,
   tagged `#[AutoBind]`.
8. `app/Domain/{Domain}/Repositories/{Entity}QueryRepository.php` — read side.

### DTOs / read models (2 files)
9. `app/Domain/{Domain}/DTOs/{Entity}DTO.php`
10. `app/Domain/{Domain}/ReadModels/{Entity}View.php` — legacy projection
    shape; to be merged into `{Entity}DTO` by E10.

### Value objects (5 files for a stock-aware domain)
11. `app/Domain/{Domain}/ValueObjects/{Entity}Name.php`
12. `app/Domain/{Domain}/ValueObjects/{Entity}Price.php`
13. `app/Domain/{Domain}/ValueObjects/{Entity}Stock.php`
14. `app/Domain/{Domain}/ValueObjects/{Entity}Snapshot.php` — before/after
    diff carried by change events (E08).
15. `app/Domain/{Domain}/ValueObjects/StockChangeReason.php` — enum (E08).

### Commands (8 files — 4 commands × 2 files)
16. `app/Domain/{Domain}/Commands/Create{Entity}/Create{Entity}Command.php`
17. `app/Domain/{Domain}/Commands/Create{Entity}/Create{Entity}Handler.php`
18. `app/Domain/{Domain}/Commands/Update{Entity}/Update{Entity}Command.php`
19. `app/Domain/{Domain}/Commands/Update{Entity}/Update{Entity}Handler.php`
20. `app/Domain/{Domain}/Commands/Delete{Entity}/Delete{Entity}Command.php`
21. `app/Domain/{Domain}/Commands/Delete{Entity}/Delete{Entity}Handler.php`
22. `app/Domain/{Domain}/Commands/Restore{Entity}/Restore{Entity}Command.php`
23. `app/Domain/{Domain}/Commands/Restore{Entity}/Restore{Entity}Handler.php`

### Queries (6 files — 3 queries × 2 files)
24. `app/Domain/{Domain}/Queries/Get{Entity}ById/Get{Entity}ByIdQuery.php`
25. `app/Domain/{Domain}/Queries/Get{Entity}ById/Get{Entity}ByIdHandler.php`
26. `app/Domain/{Domain}/Queries/GetAll{Entities}/GetAll{Entities}Query.php`
27. `app/Domain/{Domain}/Queries/GetAll{Entities}/GetAll{Entities}Handler.php`
28. `app/Domain/{Domain}/Queries/Get{Entities}Paginated/Get{Entities}PaginatedQuery.php`
29. `app/Domain/{Domain}/Queries/Get{Entities}Paginated/Get{Entities}PaginatedHandler.php`

### Events (14 files — 7 events × 2 files)
Every event **MUST** extend
`\App\Domain\Shared\Events\AbstractDomainEvent` (E04).

30. `app/Domain/{Domain}/Events/{Entity}Created/{Entity}CreatedEvent.php`
31. `app/Domain/{Domain}/Events/{Entity}Created/{Entity}CreatedEventHandler.php`
32. `app/Domain/{Domain}/Events/{Entity}Updated/{Entity}UpdatedEvent.php`
33. `app/Domain/{Domain}/Events/{Entity}Updated/{Entity}UpdatedEventHandler.php`
34. `app/Domain/{Domain}/Events/{Entity}Deleted/{Entity}DeletedEvent.php`
35. `app/Domain/{Domain}/Events/{Entity}Deleted/{Entity}DeletedEventHandler.php`
36. `app/Domain/{Domain}/Events/{Entity}Restored/{Entity}RestoredEvent.php`
37. `app/Domain/{Domain}/Events/{Entity}Restored/{Entity}RestoredEventHandler.php`
38. `app/Domain/{Domain}/Events/{Entity}Activated/{Entity}ActivatedEvent.php`
39. `app/Domain/{Domain}/Events/{Entity}Activated/{Entity}ActivatedEventHandler.php`
40. `app/Domain/{Domain}/Events/{Entity}Deactivated/{Entity}DeactivatedEvent.php`
41. `app/Domain/{Domain}/Events/{Entity}Deactivated/{Entity}DeactivatedEventHandler.php`
42. `app/Domain/{Domain}/Events/{Entity}StockChanged/{Entity}StockChangedEvent.php`
43. `app/Domain/{Domain}/Events/{Entity}StockChanged/{Entity}StockChangedEventHandler.php`

### Services (optional, 1 file)
44. `app/Domain/{Domain}/Services/PriceFormatter.php` — example of a
    domain-private utility. Optional per-domain.

### Projections (template, 1 reference file)
45. `app/Domain/{Domain}/Projections/{Entity}ReadModelProjection.php.example`
    — see `PROJECTIONS.md` for how to re-enable.

---

## Infrastructure layer (2 files)

46. `app/Models/{Domain}/{Entity}Model.php` — thin CI4 Model
47. `app/Infrastructure/Persistence/Repositories/{Entity}Repository.php` —
    **NOTE:** the canonical Cookie domain has moved its repository under
    `app/Domain/Cookie/Repositories/` (file #7 above). New domains follow
    that layout; the legacy `app/Infrastructure/Persistence/Repositories/`
    path is retained only for adapters that genuinely cross domain
    boundaries.

---

## Application layer (1 file)

48. `app/Controllers/Domain/{Domain}/{Entity}Controller.php` — thin
    controller, dispatches via `CommandBus` / `QueryBus`.

---

## Presentation layer (4 files)

49. `app/Views/{entities}/index.php`
50. `app/Views/{entities}/show.php`
51. `app/Views/{entities}/create.php`
52. `app/Views/{entities}/edit.php`

E14 will collapse these into shared partials.

---

## Database layer (1 file)

53. `app/Database/Migrations/YYYY-MM-DD-HHMMSS_Create{Entities}Table.php` —
    ISO date prefix; `version` + `deleted_at` columns required.

---

## Test layer (19+ files)

### Unit tests — value objects (4 files)
54. `tests/Unit/Domain/{Domain}/ValueObjects/{Entity}NameTest.php`
55. `tests/Unit/Domain/{Domain}/ValueObjects/{Entity}PriceTest.php`
56. `tests/Unit/Domain/{Domain}/ValueObjects/{Entity}SnapshotTest.php`
57. `tests/Unit/Domain/{Domain}/ValueObjects/StockChangeReasonTest.php`

### Unit tests — entity + DTO + view + provider (4 files)
58. `tests/Unit/Domain/{Domain}/Entities/{Entity}Test.php`
59. `tests/Unit/Domain/{Domain}/DTOs/{Entity}DTOTest.php`
60. `tests/Unit/Domain/{Domain}/ReadModels/{Entity}ViewTest.php`
61. `tests/Unit/Domain/{Domain}/{Domain}ServiceProviderTest.php`

### Unit tests — commands (4 files)
62. `tests/Unit/Domain/{Domain}/Commands/Create{Entity}HandlerTest.php`
63. `tests/Unit/Domain/{Domain}/Commands/Update{Entity}HandlerTest.php`
64. `tests/Unit/Domain/{Domain}/Commands/Delete{Entity}HandlerTest.php`
65. `tests/Unit/Domain/{Domain}/Commands/Restore{Entity}HandlerTest.php`

### Unit tests — queries (3 files)
66. `tests/Unit/Domain/{Domain}/Queries/Get{Entity}ByIdHandlerTest.php`
67. `tests/Unit/Domain/{Domain}/Queries/GetAll{Entities}HandlerTest.php`
68. `tests/Unit/Domain/{Domain}/Queries/Get{Entities}PaginatedHandlerTest.php`

### Unit tests — events (2 aggregate files + per-event detail tests)
69. `tests/Unit/Domain/{Domain}/Events/{Entity}EventsTest.php` — every event payload
70. `tests/Unit/Domain/{Domain}/Events/{Entity}EventHandlersTest.php` — every handler
71. `tests/Unit/Domain/{Domain}/Events/{Entity}Activated/{Entity}ActivatedEventTest.php`
72. `tests/Unit/Domain/{Domain}/Events/{Entity}Deactivated/{Entity}DeactivatedEventTest.php`

### Integration tests (3 files)
73. `tests/Integration/Repositories/{Entity}RepositoryTest.php`
74. `tests/Integration/Repositories/{Entity}QueryRepositoryTest.php`
75. `tests/Integration/Repositories/{Entity}OptimisticLockingTest.php`

### Feature tests (2 files)
76. `tests/Feature/{Domain}/{Entity}CrudTest.php`
77. `tests/Feature/{Domain}/{Entity}QueryE2ETest.php`

### Test factories (1 file)
78. `tests/Support/Factories/{Entity}Factory.php`

---

## Configuration (0 new files; 0 mandatory edits)

You **do NOT** edit `app/Config/Services.php` or `app/Config/Routes.php`
for a new domain:

- Routes live inside `{Domain}ServiceProvider::registerRoutes()`.
- Repositories are bound by tagging the concrete class with `#[AutoBind]`.
- The provider is auto-discovered by `ServiceProviderRegistry`.

---

## File count by layer

| Layer | File count |
|-------|------------|
| Domain layer | 45 files |
| Infrastructure layer | 2 files |
| Application layer | 1 file |
| Presentation layer | 4 files |
| Database layer | 1 file |
| Test layer | 19+ files |
| Config updates | 0 |
| **Total** | **72+ files** |

The Cookie reference domain itself produces a slightly smaller file set
because not every event needs a per-event detail test, but the canonical
tree above is the target for any new ERP domain.

---

## Canonical Cookie tree (as of PRs #29-#39)

```
app/Domain/Cookie/
├── CookieServiceProvider.php
├── ErrorCodes.php
├── Commands/
│   ├── CreateCookie/{CreateCookieCommand,CreateCookieHandler}.php
│   ├── UpdateCookie/{UpdateCookieCommand,UpdateCookieHandler}.php
│   ├── DeleteCookie/{DeleteCookieCommand,DeleteCookieHandler}.php
│   └── RestoreCookie/{RestoreCookieCommand,RestoreCookieHandler}.php
├── Queries/
│   ├── GetCookieById/{GetCookieByIdQuery,GetCookieByIdHandler}.php
│   ├── GetAllCookies/{GetAllCookiesQuery,GetAllCookiesHandler}.php
│   └── GetCookiesPaginated/{GetCookiesPaginatedQuery,GetCookiesPaginatedHandler}.php
├── Events/
│   ├── CookieCreated/{CookieCreatedEvent,CookieCreatedEventHandler}.php
│   ├── CookieUpdated/{CookieUpdatedEvent,CookieUpdatedEventHandler}.php
│   ├── CookieDeleted/{CookieDeletedEvent,CookieDeletedEventHandler}.php
│   ├── CookieRestored/{CookieRestoredEvent,CookieRestoredEventHandler}.php
│   ├── CookieActivated/{CookieActivatedEvent,CookieActivatedEventHandler}.php
│   ├── CookieDeactivated/{CookieDeactivatedEvent,CookieDeactivatedEventHandler}.php
│   └── CookieStockChanged/{CookieStockChangedEvent,CookieStockChangedEventHandler}.php
├── Entities/
│   ├── Cookie.php
│   └── CookieStateAssertions.php
├── ValueObjects/
│   ├── CookieName.php
│   ├── CookiePrice.php
│   ├── CookieStock.php
│   ├── CookieSnapshot.php
│   └── StockChangeReason.php
├── DTOs/
│   └── CookieDTO.php
├── ReadModels/
│   └── CookieView.php                # collapse pending E10
├── Ports/
│   ├── CookieRepositoryInterface.php
│   └── CookieQueryRepositoryInterface.php
├── Repositories/
│   ├── CookieRepository.php
│   └── CookieQueryRepository.php
├── Services/
│   └── PriceFormatter.php
└── Projections/
    └── CookieReadModelProjection.php.example

app/Models/Cookie/CookieModel.php
app/Controllers/Domain/Cookie/CookieController.php
app/Views/cookies/{index,show,create,edit}.php
app/Database/Migrations/2025-01-21-000001_CreateCookiesTable.php
```

---

## Notes

- Class counts assume the canonical Cookie shape; domains with fewer
  events or value objects produce smaller trees.
- All files MUST pass PHPStan Level 8 and Slevomat standards.
- All files MUST be covered by tests (≥ 90 % statement coverage gate).
- Every event MUST extend `AbstractDomainEvent`.
- Every entity MUST implement `AggregateRootInterface`.
- Every handler MUST implement the typed bus interface (or extend the
  template-method base from `app/Domain/Shared/Bus/`).
- The `Money` value object lives in `app/Domain/Shared/ValueObjects/` and
  will become the canonical price representation once E09 lands; until
  then domains may still use `CookiePrice`-style per-domain VOs.
