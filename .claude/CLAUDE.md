# Project Memory — CodeIgniter 4 / CQRS / DDD Template

This file is intentionally short. It captures the rules an agent MUST know
before running its first command. Detail lives in `.claude/skills/*` and
`.claude/agents/*`; load them on demand.

## Project type

- **Stack:** PHP 8.3+ on CodeIgniter 4.6+, MySQL 8, PHPStan L8, PHPUnit 12,
  PCOV for coverage.
- **Architecture:** CQRS (commands + queries via separate buses) + DDD
  (aggregates / value objects) + Hexagonal ports & adapters.
- **Reference domain:** `app/Domain/Cookie/` — copy its structure for new
  domains. Handlers, value objects, repository (write + read sides),
  events, lifecycle methods, optimistic locking, and tests are
  exemplified there. The projection scaffold ships as a `.example` file
  (single-aggregate template does not need it active — see
  `.claude/documentation/PROJECTIONS.md`).
- **Shared bases:** Cross-cutting building blocks every domain consumes
  live under `app/Domain/Shared/`:
  - `Aggregate/AggregateRootInterface.php` + `Aggregate/AggregateHydrator.php`
    (E06) — typed contract every aggregate satisfies, with a key class
    that gates persistence-only mutators.
  - `Bus/AbstractCommandHandler.php`, `Bus/AbstractQueryHandler.php`,
    `Bus/CommandHandlerInterface.php`, `Bus/QueryHandlerInterface.php`,
    `Bus/ClockInterface.php`, `Bus/SystemClock.php`, `Bus/LogSampler.php`
    (E05) — template-method handler bases + typed bus contracts.
  - `Events/AbstractDomainEvent.php` (E04) — every event extends this
    base, carrying the 5-field envelope (`eventId`, `occurredAt`,
    `actorId`, `aggregateType`, `aggregateId`).
- **Snapshot scope:** The architecture docs (`cqrs-architecture` skill,
  `domain-scaffolding` skill, `COMPLETE_FILE_INVENTORY.md`,
  `PROJECTIONS.md`) reflect Cookie's shape after PRs #29-#39 (Phase 0 +
  Phase 1 + partial Phase 2). E09 (multi-currency), E10 (DTO
  consolidation), E11 (repo hygiene), E12 (outbox hardening), E13
  (provider DI), and E14 (view collapse) are **not** yet reflected and
  will trigger a follow-up doc refresh.

## Boot-time hard rules

### Git (NEVER skip these)

- **Conventional Commits 1.0** is required: `type(scope): subject`.
  Allowed types: `feat | fix | docs | style | refactor | perf | test | build | ci | chore | revert`.
  Allowed scopes live in `.commitlintrc.json` (`scope-enum`).
- **NEVER pass `--no-verify`** to `git commit` or `git push`. The hook is
  enforced server-side too; bypassing locally just produces a failing PR.
  Fix the underlying complaint instead.
- **NEVER `git push --force`.** Use `git push --force-with-lease --force-if-includes`
  (or the alias `git fpush`).
- **NEVER commit directly to `main`** unless the user says verbatim
  "commit directly to main". Default flow is branch → commit → PR.
- Commits are SSH-signed automatically; do not disable signing.
- AI-assisted commits must include a `Co-Authored-By:` trailer.

Full playbook + hook list + ruleset reference: see
`.claude/agents/git-specialist.md` and `.claude/documentation/GIT_WORKFLOW.md`.

### Tests

- Run tests on every change you make. The minimum gate is `composer check`
  (see "Common commands" below). Coverage floor is **90 %**.
- Reject your own work if PHPStan L8, PHPCS+Slevomat, deptrac, or
  `docblocks:audit` fail. Don't open the door for the user to find it.

### Code quality

- All PHP files start with `declare(strict_types=1);`.
- Methods ≤ 20 lines, classes ≤ 200 lines (Phase 4 will enforce mechanically).
- Final classes by default. Readonly for value objects + DTOs + events.
- One symbol per logical unit, PSR-12, descriptive names. Serena (LSP) indexes
  this codebase — see the `serena-code-generator` skill for symbol guidelines.

### File organization

- Never create `*.md` in the repo root. Documentation lives in
  `.claude/documentation/` or as skill files under `.claude/skills/`.
- Never write scratch `.php` / `.sh` files in the repo root. Use `temp/`
  (gitignored). Create the directory if it's missing.
- Production code goes in the proper domain directory under `app/Domain/`.

## Common commands

```bash
# Gate suite (run before commit; same order as CI)
composer check                              # docblocks:audit, phpcs, phpstan, phpunit

# Individual gates
composer phpcs                              # PSR-12 + Slevomat, must be 0 violations
composer phpstan                            # Level 8, must be 0 errors
composer test                               # PHPUnit, must keep >=90% coverage
composer docblocks:audit                    # must exit 0
vendor/bin/deptrac analyse --no-progress    # architectural boundaries

# Auto-fix style violations
vendor/bin/phpcbf

# Day-to-day
php spark serve
php spark migrate --all
vendor/bin/phpunit --testdox
```

## Skills — load on demand

The files below live under `.claude/skills/<name>/SKILL.md`. Each has its own
YAML frontmatter and is auto-discovered by the harness; cite the skill name
in conversation when you want it activated explicitly.

### Domain workflow

- **`domain-scaffolding`** — scaffold a brand-new domain (45+ files: entity,
  VOs, DTOs, ports, commands, queries, events, handlers, repository,
  controller, views, tests). Reference: Cookie domain.
- **`property-addition`** — add a single field to an existing entity. Walks
  the 20+ files you must touch (VO, entity, commands, handlers, repository,
  migration, database, views, tests).
- **`business-rule-addition`** — add an invariant / validation rule, with
  guidance on whether it belongs in a VO, entity, or handler.
- **`code-review`** — fans out to every specialist agent in parallel and
  returns a violation report with fixes.

### Project-internal skills (load when relevant)

- **`cqrs-architecture`** — full architecture reference: directory layout,
  command/query/event patterns, complete file inventory, naming
  conventions, auto-discovery system. Load when scaffolding a new domain
  or onboarding to the architecture.
- **`logging-architecture`** — Monolog setup, PSR-3 patterns in CQRS
  handlers, log channel naming, JSON format, correlation IDs, error codes,
  query-logging modes. Load when writing or changing log statements.
- **`testing-strategy`** — test pyramid (70/20/10), coverage gate, test
  locations. Load when writing new tests.
- **`serena-code-generator`** — Serena/LSP-friendly PHP patterns, symbol
  guidelines, examples. Load when generating new code or refactoring for
  symbol clarity.
- **`task-recovery`** — how to detect and resume an interrupted execution
  in `.claude/planning/`. Load when the user asks "do I have unfinished
  tasks?" or "resume my plan".
- **`agent-orchestration`** — the parallel-specialist delegation pattern,
  rejection policy, pre/during/post execution rules. Load when writing
  code that touches the standard PHPStan/Slevomat/PHPUnit gate.
- **`strategic-planner`** — Tree-of-Thought + SMART-E atomic-task planner
  for tasks that touch 15+ files or carry HIGH/CRITICAL risk.

### General-purpose skills

- `serena-code-assistant` — Serena MCP / LSP-powered navigation, symbol
  lookup, multi-file refactor.
- `playwright-automation`, `chrome-devtools-expert`, `context7-docs`,
  `markitdown-converter` — external-tooling adapters.

## Agents — invoke before, during, and after edits

Each lives in `.claude/agents/<name>.md`.

- **`git-specialist`** — every git operation goes through this agent.
  Enforces Conventional Commits, signed commits, no `--no-verify`, no
  force-push to `main`. Mandatory.
- **`php-specialist`** — PHP syntax + strict types + 8.3 features.
- **`clean-code-specialist`** — method/class length, readability, SOLID.
- **`cqrs-specialist`** — command/query/event/handler patterns.
- **`ddd-specialist`** — aggregates, entities, value objects, invariants.
- **`codeigniter4-specialist`** — routing, controllers, migrations.
- **`phpstan-specialist`** — Level 8 zero-error gate.
- **`slevomat-specialist`** — Slevomat ruleset + auto-fix via `phpcbf`.
- **`test-specialist`** — coverage gate (≥ 90 %) and pyramid balance.
- **`serena-code-assistant`** — semantic-symbol navigation across the tree.
- **`claude-code-specialist`** — when creating new agents / skills /
  commands.

Default orchestration: when touching a domain, delegate in PARALLEL to the
relevant specialists (e.g., new property → `ddd-specialist` +
`test-specialist` + `clean-code-specialist`). Details in the
`agent-orchestration` skill.

## When you're unsure, look here

- Architecture deep-dive + file inventory: skill `cqrs-architecture` and
  `.claude/documentation/COMPLETE_FILE_INVENTORY.md`.
- Adding a domain: `/add-domain` slash command (calls `domain-scaffolding`).
- Adding a property: `/add-property` slash command (calls `property-addition`).
- Reviewing changes: `/code-review` slash command.
- Logging: skill `logging-architecture` +
  `.claude/documentation/LOGGING_BEST_PRACTICES.md`.
- Projections / read models: `.claude/documentation/PROJECTIONS.md` plus
  the `CookieReadModelProjection.php.example` reference in the Cookie
  domain.
- Git workflow: `.claude/documentation/GIT_WORKFLOW.md` + the
  `git-specialist` agent.

That's it. Don't try to memorise everything — load the skill that matches
the work in front of you.
