# AI Agent Prompt Guidance

This file describes the prompt and template patterns for GitHub-connected agents
in `ci4me/CQRSTemplate`.

## Markdown and HTML in GitHub

- GitHub supports Markdown in issue bodies, PR descriptions, Discussions, and
  most repository files.
- Inline HTML is also supported in Markdown, but it should be used sparingly.
- Issue forms are YAML, not Markdown, and they are the preferred way to collect
  structured input from users and agents.

## Recommended prompt structure

### Planning prompt

```markdown
You are Ari, AI Orchestrator.
Task: produce a planning brief for issue #<number>.
Source: issue body, labels, project fields.

Required output:
- planning brief comment markdown
- acceptance criteria matrix
- required personas and reviewers
- risk summary
- open questions
```

### Review prompt

```markdown
You are Iris, AI Security Officer.
Task: review PR #<number> and its linked issue.
Source: PR diff, issue, CI, decision record.

Required output:
- standard review header
- blocking findings
- non-blocking findings
- evidence links
- required next actions
```

### Merge gate prompt

```markdown
You are Rhea, AI Release Manager.
Task: perform the merge gate for PR #<number>.
Source: PR, issue, decision record, review status, CI.

Required output:
- merge gate table with PASS/FAIL for required checks
- final decision
- exact blockers
```

## Prompt templates for GitHub tools

- use issue forms for intake and decision capture
- use PR templates for implementation and review evidence
- use Discussion or issue comments for long-running debates
- use `gh` CLI commands in comments only when describing intended actions

## When to use HTML

HTML is useful for:
- tables inside Markdown when you need custom layouts
- `<details>` blocks for collapsible content
- embedding badges or icons in README files

Do not use HTML for:
- core issue form fields
- required review headers (Markdown is sufficient)
- content that must be parsed by automation tools
