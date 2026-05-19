#!/usr/bin/env bash
# .claude/hooks/git-guard.sh
#
# PreToolUse hook for Claude Code's Bash tool. Inspects the command Claude is
# about to run and blocks unsafe / non-conforming git operations BEFORE the
# shell sees them.
#
# Why: the local .githooks/ are skippable with --no-verify, and even harmless
# typos can erase work (`git reset --hard origin/main`, `git push --force`).
# This hook enforces the same rules in-process for any agent operating in this
# repo, regardless of permission mode.
#
# Hook contract:
#   stdin  → JSON payload with tool_name + tool_input
#   exit 0 → allow the call
#   exit 2 → block; stderr is shown to Claude
#
# Locally testable:
#   echo '{"tool_name":"Bash","tool_input":{"command":"git push --force"}}' \
#     | .claude/hooks/git-guard.sh
set -euo pipefail

# Emergency escape valve. Set to 1 only when you understand the consequence.
# (e.g. one-time history rewrite, intentional force-push to a topic branch).
if [ "${GIT_GUARD_DISABLE:-0}" = "1" ]; then
    exit 0
fi

# Read the payload from stdin.
payload=$(cat)

# Extract tool name + command (jq is in every standard runner; fall back to grep).
if command -v jq >/dev/null 2>&1; then
    tool=$(echo "$payload"   | jq -r '.tool_name // empty')
    cmd=$(echo "$payload"    | jq -r '.tool_input.command // empty')
else
    tool=$(echo "$payload"   | grep -oP '"tool_name"\s*:\s*"\K[^"]+' || echo "")
    cmd=$(echo "$payload"    | grep -oP '"command"\s*:\s*"\K[^"]+' || echo "")
fi

# Only inspect Bash calls.
[ "$tool" = "Bash" ] || exit 0
[ -n "$cmd" ] || exit 0

# Only inspect commands that actually *start with* git (optionally after env
# assignments). Loops, pipelines, here-docs, and wrapper scripts that merely
# mention "git" in text are not our business.
if ! [[ "$cmd" =~ ^[[:space:]]*(([A-Za-z_][A-Za-z0-9_]*=[^[:space:]]+)[[:space:]]+)*git([[:space:]]+|$) ]]; then
    exit 0
fi

# Strip quoted message bodies before flag scanning, so we don't match flags
# that legitimately appear in the commit-message text we're enforcing.
# Removes:  -m "..."  /  -m '...'  /  -m "$(cat <<EOF ... EOF)"  /  same for -am/-F/-c/-C.
flag_scan=$(echo "$cmd" \
    | sed -E 's/-[mFCc]m?[[:space:]]+"\$\([^)]*\)"//g' \
    | sed -E 's/-[mFCc]m?[[:space:]]+"[^"]*"//g' \
    | sed -E "s/-[mFCc]m?[[:space:]]+'[^']*'//g" )

# ─── Hard blocks ──────────────────────────────────────────────────────────────
# Each rule scans the *flag-only* projection of the command (message bodies stripped).
deny_rule() {
    local pattern="$1" reason="$2" hint="$3"
    if [[ "$flag_scan" =~ $pattern ]]; then
        {
            echo "[git-guard] BLOCKED: $reason"
            echo "  Command: $cmd"
            echo "  Hint:    $hint"
        } >&2
        exit 2
    fi
}

# Never skip local hooks — that's why we built them.
deny_rule '--no-verify' \
    'git --no-verify is disallowed. The hooks exist to prevent broken commits/pushes.' \
    'Fix the issue the hook is reporting instead of bypassing.'

# Plain --force loses history. Require --force-with-lease.
deny_rule 'git[[:space:]]+push[[:space:]]+([^&;|]*[[:space:]])?-f($|[[:space:]])' \
    'git push -f is disallowed.' \
    'Use `git push --force-with-lease --force-if-includes` (alias: git fpush).'
deny_rule 'git[[:space:]]+push[[:space:]]+([^&;|]*[[:space:]])?--force($|[[:space:]])' \
    'git push --force is disallowed (collateral damage too easy).' \
    'Use `git push --force-with-lease --force-if-includes` (alias: git fpush).'

# Hard reset to a remote ref silently discards local work — almost always a mistake.
deny_rule 'git[[:space:]]+reset[[:space:]]+--hard[[:space:]]+(origin|upstream)/' \
    'git reset --hard <remote>/<branch> would discard local work.' \
    'Stash or commit first; if you really want this, run it from a real terminal.'

# Globally rewriting user config inside a project session is suspicious.
deny_rule 'git[[:space:]]+config[[:space:]]+(--global|--system)' \
    'git config --global / --system is not allowed from this repo.' \
    'Edit project-local config (.git/config) or commit changes under .githooks/.'

# Direct commits to protected branches.
current_branch=$(git -C "$(pwd)" symbolic-ref --short HEAD 2>/dev/null || echo "")
if [[ "$cmd" =~ git[[:space:]]+commit ]] && [[ "$current_branch" =~ ^(main|master)$ ]]; then
    {
        echo "[git-guard] BLOCKED: committing directly to '$current_branch'."
        echo "  Create a feature branch first:"
        echo "    git switch -c feat/<scope>-<short-description>"
    } >&2
    exit 2
fi

# ─── Conventional Commits validation on `git commit -m` ───────────────────────
# Pull the subject out of the command. We can only reliably parse the literal
# inline form: -m "msg" or -m 'msg'. For -F file or -m "$(...)" / heredocs the
# subject only exists at execution time — we defer to the local commit-msg hook.
if [[ "$cmd" =~ git[[:space:]]+commit ]] \
   && ! [[ "$cmd" =~ -m[[:space:]]+\"\$\( ]] \
   && ! [[ "$cmd" =~ -F[[:space:]] ]] \
   && [[ "$cmd" =~ -m[[:space:]]+([\"\'])([^\$\<].*) ]]; then
    quote="${BASH_REMATCH[1]}"
    rest="${BASH_REMATCH[2]}"
    subject="${rest%%${quote}*}"

    if ! [[ "$subject" =~ ^(Merge|Revert|fixup!|squash!) ]]; then
        cc_pattern='^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)(\([a-z0-9._-]+\))?!?: .{1,100}$'
        if ! [[ "$subject" =~ $cc_pattern ]]; then
            {
                echo "[git-guard] BLOCKED: commit subject violates Conventional Commits."
                echo "  Subject: $subject"
                echo "  Expected: type(scope)!: subject"
                echo "    type   = feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert"
                echo "    scope  = optional, lowercase"
                echo "    !      = optional, marks breaking change"
                echo "  Examples:"
                echo "    feat(cookie): add stock-decrement business rule"
                echo "    fix(order)!: reject negative quantity"
            } >&2
            exit 2
        fi
        if [[ "$subject" =~ \.$ ]]; then
            echo "[git-guard] BLOCKED: subject must not end with a period." >&2
            exit 2
        fi
    fi
fi

exit 0
