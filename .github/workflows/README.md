# GitHub Actions workflows

Quick reference for the CI lanes and the matrix axes they exercise.
Round-3 audit findings closed here: `13/F1`, `13/F2`, `13/F17`,
`18/F-T1`, `18/F-T2`. Plan ref: `.audit/round3/REMEDIATION-PLAN.md`
(E01).

## ci.yml — the main pipeline

Three independent jobs plus a coverage-merge gate.

### `quality`
Lint, PHPStan Level 8, PHPCS (PSR-12 + Slevomat). Runs once on PHP
`${{ env.PHP_VERSION }}`. No DB. Composer + PHPCS + PHPStan caches keyed
by `composer.lock` / `phpcs.xml` / `phpstan.neon` + sources.

### `tests`
PHPUnit matrix — same suite runs against both DB backends. Coverage is
collected on each axis with PCOV. Migrate-up / migrate-down / migrate-up
smoke step runs on the MySQL lane only (forward-and-back protects
against irreversible schema diffs).

| Axis           | Values        |
|----------------|---------------|
| `matrix.php`   | `['8.4']`     |
| `matrix.db`    | `['sqlite', 'mysql']` |

MySQL lane uses `mysql:8.0.36` as a GitHub Actions service container
(image tag pinned — REVIEW-tests round-3 required change #1). Database
name `ci4me_test`, user `ci4me`/`ci4me`, root password `root`. Host port
`3306` inside the runner. `pcntl` extension is enabled so E18's
concurrency tests (forthcoming) can fork real worker processes.

The two lanes diverge only at the "Configure environment" step:
- **SQLite** uses `phpunit.xml.dist` defaults (`database.tests.DBDriver =
  SQLite3`, `:memory:`).
- **MySQL** overrides those same env vars via `.env` + `$GITHUB_ENV` so
  the suite executes against MySQL 8. Because `phpunit.xml.dist` no
  longer forces `SQLite3` (13/F1), the override takes effect.

### `coverage-merge`
`needs: tests`. Downloads every `coverage-php*-*` artifact, runs
`phpcov merge --clover build/logs/clover.merged.xml ...` on the
serialised coverage payloads, then enforces `>= 90%` against the
**merged** union (REVIEW-tests round-3 required change #4). A per-lane
gate would be impossible to satisfy because lane-skipped tests would
drop integration files below threshold on the other lane.

### `secrets`
Gitleaks scan on full history (PR + push). Independent of the test
matrix.

## Other workflows

- `codeql.yml` — CodeQL security scan (Microsoft GitHub).
- `commitlint.yml` — Conventional Commits gate on PR titles + commit
  bodies.
- `dependency-review.yml` — fail PR on known-vulnerable dependency
  additions.
- `release-please.yml` — Conventional Commits → release PRs.
- `scorecard.yml` — OpenSSF Scorecard for supply-chain hygiene.

## Local reproduction of the MySQL lane

```bash
# Pre-req: Docker + docker compose plugin
make test-mysql
```

Spins up `docker-compose.yml` (same `mysql:8.0.36`), waits for the
healthcheck, runs `composer test` with the same `database.tests.*` env
overrides the CI MySQL lane sets, then tears the container down on
exit (success, Ctrl-C, or failure). The compose project name is
`ci4me-test`; the host port is `33060` → `3306` inside so a separate
local MySQL on `3306` is not disturbed.

If `docker compose` is not available the CI lane is still authoritative
— there is no other supported path to reproduce MySQL-only failures
locally. Install Docker Desktop / `docker-buildx-plugin` /
`docker-compose-plugin` and re-run.

## Reproducing a single CI failure

1. Open the failed run; note `matrix.db` (`sqlite` or `mysql`).
2. `sqlite` → `composer test` reproduces it 1:1 locally.
3. `mysql` → `make test-mysql` reproduces it 1:1 locally.
4. If only the merged coverage gate failed, download the
   `coverage-merged` artifact and inspect the per-file figures with
   `vendor/bin/phpunit --coverage-html build/logs/html` after a local
   run.
