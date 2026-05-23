# RE-AUDIT 06 — Repository Ports & Adapters (Round 3)

**Original audit:** `.audit/round3/06-repository-ports.md` (17 findings: F1..F17)
**Reviewer:** ddd-specialist
**Date:** 2026-05-23
**PRs claimed to land between rounds:**
- PR #33 (E06 — `AggregateRootInterface` + `AggregateHydrator` key used in `save()`)
- PR #41 (E11 — repository hygiene: `existsByName` schema reconciliation, LIKE escape, `fromTrusted`, single-statement delete, restore version bump, `purge()`, `BaseConnection` generic drop, model validation narrowing, `lastAffectedRows` wrapper)

## TL;DR
**E06 landed; E11 did NOT land.** `AggregateHydrator::key()` is wired through `performSave()` / `assignId()` / `bumpVersion()`, which closes the lifecycle-key gap, but every other F# from this slice is byte-identical to the round-3 source: `existsByName` still calls `withDeleted()` + `LOWER(name)`, `CookieRepository::executeFindPaginated` still orders `created_at DESC`, the read repo still orders `id ASC` and still swallows `get() === false`, `delete()` still SELECTs before UPDATE, `restore()` still bypasses optimistic locking and returns the raw QB truthy, `LIKE` is still unescaped, `toDomainEntity()` still re-runs full VO validation, `CookieModel` still has duplicated validation rules and a public `$db` leak, the write port still declares `existsByName(string $name)`, and the traits still hard-code `'cookie'` / `'Cookie'` / `'CookieRepository'` literals. Verdict moves from READY-WITH-FIXES to NOT-READY because the round-3 review explicitly counted on PR #41 landing.

## Verdict
NOT-READY (regressed expectations: E11 did not land)

## Per-finding status

### F1 — HIGH — `existsByName` contradicts schema reuse-after-soft-delete (E11)
- **Status:** OPEN
- **Evidence:** `app/Models/Cookie/CookieModel.php:93-98` still calls `withDeleted()->where('LOWER(name)', strtolower($name))`. Mirror at lines 109-115 for `existsByNameExcludingId`. Migration's documented B16/B17 reuse-after-delete contract continues to be violated by the handler-side check.
- **Residual risk:** unchanged. First soft-delete of a name permanently blocks re-creation in every cloned domain.

### F2 — HIGH — Hard-coded `'cookie'` metric slice key survives sed (E13)
- **Status:** OPEN (E13 was always out-of-scope for round 3 PRs; flag carried)
- **Evidence:** `app/Models/Cookie/Traits/BusinessMetricsLogging.php:47,54` still reads `$this->loggingConfig->metricsThresholds['cookie'] ?? []` as a literal. No `protected string $metricsSliceKey` indirection has been introduced.
- **Residual risk:** unchanged. Any domain cloned by namespace-rename silently reads the wrong metric slice and falls back to defaults.

### F3 — HIGH — `'Cookie'` / `'CookieRepository'` literals in logging traits
- **Status:** OPEN
- **Evidence:** `RepositoryLogging.php:25-58, 75-81, 104-114` still emit `'domain' => 'Cookie'` and `'repository' => 'CookieRepository'`; `BusinessMetricsLogging.php:72-78, 104-112, 135-141` likewise. `ErrorCodes::COOKIE_REPOSITORY_*` constants also still inline.
- **Residual risk:** unchanged. Production logs from a cloned domain will misattribute every error to the Cookie domain.

### F4 — HIGH — Unescaped LIKE wildcard injection (E11)
- **Status:** OPEN
- **Evidence:** `CookieQueryRepository.php:131` still `$builder->like('name', $searchTerm)` raw; mirror at `CookieRepository.php:555` (note: round-3 review pointed at line 554; current file is :555). No `strtr($term, ['%' => '\\%', '_' => '\\_'])` pre-escape, no explicit side argument.
- **Residual risk:** unchanged. `%` / `_` in user search term still bypass intent and force pathological scans.

### F5 — MED — Write/read `findPaginated` sort divergence (E11)
- **Status:** OPEN
- **Evidence:** `CookieRepository.php:569` orders `created_at DESC`; `CookieQueryRepository.php:138` orders `id ASC`. Neither port has been removed; no shared ORDER BY constant introduced.
- **Residual risk:** unchanged. Caller experience depends on which port the handler picked.

### F6 — MED — `LOWER(name)` voids the UNIQUE index (E11)
- **Status:** OPEN
- **Evidence:** `CookieModel.php:96, 112` still use `where('LOWER(name)', strtolower($name))` despite the migration's pinned `utf8mb4_unicode_ci` collation that already provides case-insensitive uniqueness for free.
- **Residual risk:** unchanged. Uniqueness check is O(N) on every create/update in every cloned domain.

### F7 — MED — Reconstitute re-validates VOs on every row (E11)
- **Status:** OPEN
- **Evidence:** `CookieRepository.php:378-380` still calls `CookieName::fromString((string) $data['name'])` and `CookiePrice::fromString((string) $data['price'])`. `CookieName.php:80-83` exposes only `fromString()`; no `fromTrusted()` factory has been added. A legacy / migration-shrunk row still poisons every list call.
- **Residual risk:** unchanged. Trust-boundary leak persists.

### F8 — MED — `delete()` SELECT-then-UPDATE round-trip count (E11)
- **Status:** OPEN
- **Evidence:** `CookieRepository.php:297-318` still calls `findById($id)` (which triggers VO reconstitution and `trackPopularCookie`) before the optional `deleted_by` UPDATE and `model->delete($id)`. Three round-trips remain.
- **Residual risk:** unchanged. Worse, the `findById` preload still mis-bumps the popularity metric (see F12) on every delete.

### F9 — MED — `restore()` no version bump, no `affectedRows()` check (E11)
- **Status:** OPEN
- **Evidence:** `CookieRepository.php:344-346` still does `return $this->model->builder()->where('id', $id)->update($update);`. The `$update` array (lines 335-342) holds `deleted_at`, `deleted_by`, optionally `updated_at`/`updated_by` — no `version` increment, no `WHERE version = ?`, no `affectedRows() === 1` check.
- **Residual risk:** unchanged. Restore silently overwrites concurrent writes and returns truthy on zero matches.

### F10 — MED — Read `findPaginated` swallows `get() === false` (E11)
- **Status:** OPEN
- **Evidence:** `CookieQueryRepository.php:140-142` still `$rows = $result === false ? [] : $result->getResultArray();`. The write side (`CookieRepository.php:572-574`) correctly throws — asymmetry remains.
- **Residual risk:** unchanged. Failed fetch returns `total > 0` with empty `data`.

### F11 — LOW — `CookieModel` non-final + leaky public `$db` (E11)
- **Status:** OPEN
- **Evidence:** `CookieModel.php:28` is still `class CookieModel extends Model` (no `final`). `CookieRepository.php:476` still reaches into `$this->model->db->affectedRows()`; `:471, :307, :344` still call `$this->model->builder()` directly. No `lastAffectedRows()` or `queryBuilder()` wrapper has been added.
- **Note for round 4:** the round-3 plan acknowledges keeping the class non-final for mock-ability is acceptable; the leak is the part E11 was meant to wrap.
- **Residual risk:** unchanged. Cloned domains inherit the leak.

### F12 — LOW — Write-side `findById` mutates popularity counter (E11)
- **Status:** OPEN
- **Evidence:** `CookieRepository.php:209` still calls `$this->trackPopularCookie($id)` inside `findById`, so every save/delete preload also bumps the popularity metric.
- **Residual risk:** unchanged. Metric remains polluted by internal preloads.

### F13 — LOW — `save()` undocumented entity mutation (E11)
- **Status:** OPEN
- **Evidence:** Port docblock at `CookieRepositoryInterface.php:19-28` documents the `Actor` audit-stamping behaviour but still does NOT mention that `save()` mutates the caller's `Cookie` instance via `assignId()` (on insert) and `bumpVersion()` (every persist). The mutation itself remains at `CookieRepository.php:426, 433, 453`.
- **Residual risk:** unchanged. The hidden side-effect surface is still undeclared at the port.

### F14 — LOW — No hard-delete / `purge()` escape hatch (E11)
- **Status:** OPEN
- **Evidence:** `CookieRepositoryInterface.php` declares `save`, `findById`, `findAll`, `findPaginated`, `existsByName`, `existsByNameExcludingId`, `delete`, `restore`, `findByIdWithTrashed`. No `purge(int $id): bool` method. GDPR / right-to-erasure still requires bypassing the port.
- **Residual risk:** unchanged.

### F15 — LOW — `BaseConnection` generic over-specified (E11)
- **Status:** OPEN
- **Evidence:** `CookieQueryRepository.php:44, 52, 206-211` still annotate `BaseConnection<object|resource|false, object|resource|false>`. Test/mock DX asymmetry between read and write adapters persists.
- **Residual risk:** unchanged.

### F16 — INFO — Model validation rules duplicate VO invariants (E11)
- **Status:** OPEN
- **Evidence:** `CookieModel.php:56-79` still carries `required|min_length[3]|max_length[100]`, `required|decimal|greater_than[0]`, `required|integer|greater_than_equal_to[0]` — bit-for-bit duplicating `CookieName` / `CookiePrice` / `CookieStock`. No narrowing to "DB-shape safety only" has happened.
- **Residual risk:** unchanged. Two validation layers, divergence hazard on every VO range change.

### F17 — INFO — Write port `existsByName*` takes raw `string` (E11)
- **Status:** OPEN
- **Evidence:** `CookieRepositoryInterface.php:53, 58` still declare `existsByName(string $name): bool` and `existsByNameExcludingId(string $name, int $excludeId): bool`. No `CookieName` VO at the port boundary; the adapter (`CookieRepository.php:271-286`) reflects the same signatures.
- **Residual risk:** unchanged. Port still bypasses its own VO contract.

## Side-effects observed from PRs that DID land (E06)
- `CookieRepository::performSave()` now calls `$cookie->bumpVersion(AggregateHydrator::key())` (lines 426, 433) and `$cookie->assignId($newId, AggregateHydrator::key())` (line 453). This closes the "public @internal" leak the round-3 reviewer flagged in slice 01 F5, even though no F# in slice 06 directly targeted it. **No regressions introduced** — the call sites compile and the existing optimistic-locking flow is preserved.
- The interface remains CI4-free (still only imports `Cookie` + `Actor`), so E06 did not contaminate the port.

## Closure summary

| Severity | Total | Closed | Open | Notes |
|----------|------:|-------:|-----:|-------|
| HIGH     |     4 |      0 |    4 | F1, F2, F3, F4 — none touched. E11 / E13 did not land. |
| MEDIUM   |     6 |      0 |    6 | F5..F10 — none touched. |
| LOW      |     5 |      0 |    5 | F11..F15 — none touched. |
| INFO     |     2 |      0 |    2 | F16, F17 — none touched. |
| **Total**| **17**|  **0** | **17** | E06 landed but addresses a slice-01 finding, not anything in slice 06. |

## Verdict shift
Round 3: **READY-WITH-FIXES** (assuming E11 lands)
Round 3 re-audit: **NOT-READY** (E11 did not land; 0 / 17 closed)

## Biggest residual
F1 + F6 together: `CookieModel::existsByName` still both contradicts the schema's reuse-after-soft-delete contract AND voids the UNIQUE index it relies on. Two HIGH-severity defects in the same five-line method, untouched between rounds. Until E11 lands, this is the single highest-leverage fix in the slice — one diff (drop `withDeleted()`, drop `LOWER()`) closes one HIGH and one MEDIUM at zero risk.
