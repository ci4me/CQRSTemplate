# Security Policy

## Reporting a vulnerability

**Please do not open a public issue.**

Report security issues privately via GitHub's
[private vulnerability reporting](https://github.com/ci4me/CQRSTemplate/security/advisories/new),
or by email to <gabrielcmpaiva@gmail.com> with the subject
`[security] CQRSTemplate <short description>`.

You should expect:

- Acknowledgement within **3 business days**.
- A triage decision within **7 business days**.
- A patch and disclosure plan agreed before any public discussion.

## Scope

| In scope                                | Out of scope                                 |
|----------------------------------------|----------------------------------------------|
| Code under `app/`                       | Third-party services we depend on (report upstream) |
| Configuration that ships in this repo  | Issues only reproducible with `--no-verify` or modified hooks |
| CI workflows in `.github/workflows/`   | Volunteered example data (`.env.example`, fixtures) |

## Supply-chain hygiene

This repository enforces, on every PR to `main`:

- **PHPStan Level 8** static analysis
- **PHPCS** with Slevomat + PSR-12
- **Gitleaks** content-level secret scanning
- **CodeQL** for Actions and any JS/TS surface
- **OSSF Scorecard** weekly
- **`dependency-review-action`** blocks high-severity CVEs and unapproved licenses
- All GitHub Actions are **pinned to commit SHAs** (auto-bumped by Dependabot)

If you find a way to circumvent any of these (other than legitimate
`--no-verify` use on personal feature branches), that is a security issue.

## Supported versions

`main` is the only supported branch. Tagged releases follow
[Semantic Versioning](https://semver.org/) via release-please. Security fixes
are issued only for the latest minor of each major version.
