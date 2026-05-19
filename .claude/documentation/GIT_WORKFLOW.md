# Git Workflow

The single source of truth for how commits, branches, and pushes are made in
this repo. The rules are enforced at **three layers** — local hooks, the
Claude Code PreToolUse hook, and GitHub server-side checks — so the behaviour
is the same whether the commit comes from a human, an IDE, or an AI agent.

```
┌──────────────────────────────┐    ┌──────────────────────────────┐    ┌──────────────────────────────┐
│  Local hooks (.githooks/)    │ →  │  Claude PreToolUse hook      │ →  │  GitHub status + ruleset     │
│  per-commit / per-push       │    │  (.claude/hooks/git-guard.sh)│    │  (.github/workflows + rules) │
│  skipped by --no-verify *    │    │  skipped by GIT_GUARD_DISABLE│    │  cannot be skipped           │
└──────────────────────────────┘    └──────────────────────────────┘    └──────────────────────────────┘
                                                                  *both bypasses are themselves blocked

* `--no-verify` is rejected by .claude/hooks/git-guard.sh and by GitHub commitlint,
  so the only way to bypass the local hook is to also bypass the agent hook AND
  succeed at server-side commitlint — which won't happen with a malformed message.
```

## TL;DR for new contributors

```bash
git clone https://github.com/ci4me/CQRSTemplate.git
cd CQRSTemplate
composer install                                    # also wires .githooks/ via bin/setup-hooks
git switch -c feat/cookie-add-flavor                 # always branch first
# edit code …
git add app/Domain/Cookie/                           # stage what you intend
git commit -m "feat(cookie): add flavor value object"# pre-commit runs phpcs+phpstan+gitleaks
git push                                             # pre-push runs PHPUnit
# open PR → CI runs everything again → merge via squash
```

## Branch naming

`<type>/<scope>-<short-description>`

| Type | When |
|------|------|
| `feat`     | new behaviour |
| `fix`      | bug fix |
| `refactor` | code change, no behaviour change |
| `perf`     | performance improvement |
| `docs`     | docs only |
| `test`     | tests only |
| `build`    | composer / phpstan / phpcs |
| `ci`       | workflow changes |
| `chore`    | tooling, scripts, no `app/` change |

Include the issue number when one exists: `fix/order-123-negative-qty`.
The `prepare-commit-msg` hook reads the branch name and auto-prefixes your
commit subject + adds `Refs: #123`.

## Conventional Commits 1.0

```
<type>(<scope>)[!]: <subject ≤100 chars, imperative, no period>

<body — WHY, wrapped at 72 chars>

<footer>
  BREAKING CHANGE: <details + migration>
  Refs: #123, ABC-456
  Co-Authored-By: Name <email@example.com>
```

Scopes (from `.commitlintrc.json` `scope-enum`):

- Domains: `cookie`, `order`, `product`, `user`, `shared`
- Cross-cutting: `cqrs`, `ddd`, `infra`, `logging`, `mcp`
- Plumbing: `tests`, `docs`, `config`, `tooling`, `repo`, `deps`, `ci`

## The hooks

| Hook | Layer | What it does |
|------|-------|--------------|
| `prepare-commit-msg` | `.githooks/` | Auto-prefixes subject + adds `Refs: #N` from branch name |
| `commit-msg`         | `.githooks/` | Rejects non-Conventional-Commits subjects |
| `pre-commit`         | `.githooks/` | `php -l` + PHPCS (auto-`phpcbf`) + PHPStan L8 + gitleaks, **on staged files only** |
| `pre-push`           | `.githooks/` | Blocks WIP/force on `main`/`develop`/`release/*`, runs PHPUnit |
| `git-guard.sh`       | `.claude/hooks/` | Claude Code agent-level: blocks `--no-verify`, `--force`, hard reset to remote, global git-config, commits on `main`, bad subjects |

## Server-side checks (GitHub)

| Workflow | Trigger | Gates |
|----------|---------|-------|
| `ci.yml`                  | push/PR    | PHPStan · PHPCS · PHPUnit · coverage ≥ 90% · gitleaks |
| `commitlint.yml`          | push/PR    | Every commit in the PR is Conventional Commits 1.0 |
| `dependency-review.yml`   | PR         | No new high-severity CVEs · no non-allowed licenses |
| `codeql.yml`              | push/PR/cron | SAST on Actions + JS/TS |
| `scorecard.yml`           | weekly + push to main | OpenSSF supply-chain score |
| `release-please.yml`      | push to main | Opens release PR + updates `CHANGELOG.md` from commits |

The branch ruleset on `main` requires:

- PR (no direct pushes)
- All status checks above passing
- Linear history (no merge commits)
- No force-pushes, no deletions
- Signed commits

## Signed commits

Configured locally to sign every commit with `~/.ssh/git_signing_ed25519`. The
public key must be uploaded to GitHub as a **Signing Key** (not the same slot
as auth keys) for commits to show "Verified" on github.com:

```bash
gh ssh-key add ~/.ssh/git_signing_ed25519.pub --title "<machine>" --type signing
# requires: gh auth refresh -h github.com -s admin:ssh_signing_key
```

Or paste it manually at <https://github.com/settings/ssh/new?type=signing>.

## Aliases

Project-local aliases (run `git aliases` to list):

| Alias | Expands to |
|-------|-----------|
| `git s`        | `status -sb` |
| `git lg`       | pretty graph log of all branches |
| `git recent`   | branches sorted by last commit |
| `git sync`     | `pull --rebase --autostash && push` |
| `git cleanup`  | `fetch --prune && delete branches merged into main` |
| `git fpush`    | `push --force-with-lease --force-if-includes` |
| `git amend`    | `commit --amend --no-edit` |
| `git undo`     | `reset --soft HEAD~1` (keep changes staged) |
| `git uncommit` | `reset --mixed HEAD~1` (keep changes unstaged) |
| `git wipe`     | save WIP commit then drop it (safe scratch reset) |
| `git who`      | `shortlog -sne --no-merges` |
| `git staged`   | `diff --cached` |
| `git aliases`  | list every alias |

## Releases

Releases are managed by **release-please**:

1. Push `feat:` / `fix:` / `BREAKING CHANGE:` commits to `main`.
2. The workflow opens (or updates) a single "release PR" with the next semver,
   updated `composer.json` version, and a generated `CHANGELOG.md`.
3. Merge that PR → a tag is created automatically, a GitHub Release is published.

You never write a changelog by hand — Conventional Commits feed it.

## FAQ

**Q: A pre-commit hook is failing. Can I just `--no-verify`?**
A: No. The `.claude/hooks/git-guard.sh` rejects `--no-verify` too. Fix the issue.

**Q: I rebased and need to force-push my feature branch.**
A: Use `git fpush` (force-with-lease + force-if-includes). Plain `--force` is blocked.

**Q: I want to commit something quickly on `main` to fix a typo.**
A: You can't (unless you're the user, who can pass `GIT_GUARD_DISABLE=1` once
or say "commit directly to main"). Branch + PR + auto-merge is the path.

**Q: Coverage is at 89.7%, CI is failing. Drop the gate?**
A: No — add tests. The threshold lives in `.github/workflows/ci.yml` env section.

**Q: A new GitHub Action I want to use isn't pinned to a SHA.**
A: Look it up: `gh api repos/<owner>/<repo>/git/refs/tags/<tag> --jq '.object.sha'`
and pin to the commit SHA with a `# v<tag>` comment. Dependabot updates it.

## Files involved

- `.gitattributes` — text/binary, language stats, export-ignore
- `.gitignore` — secrets, caches, generated outputs
- `.gitmessage` — commit message template
- `.gitleaks.toml` — secret-scan rules
- `.commitlintrc.json` — server-side commit validation
- `.release-please-config.json` + `.release-please-manifest.json` — release automation
- `.githooks/*` — local git hooks
- `.claude/hooks/git-guard.sh` — Claude Code PreToolUse hook
- `.claude/agents/git-specialist.md` — agent playbook
- `.claude/settings.json` — wires hooks + permission rules
- `.github/workflows/*` — CI + supply-chain workflows
- `.github/SECURITY.md`, `.github/CONTRIBUTING.md`, `.github/CODEOWNERS`
- `bin/setup-hooks` — idempotent installer (runs via `composer install`)
