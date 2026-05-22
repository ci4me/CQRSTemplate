# Review (php-specialist) — v2 Remediation Plan

## Verdict
APPROVED-WITH-CHANGES

The plan reflects the PHP-language audit slices (14 + 17) accurately: it
allocates every F# from slice 17 (F1–F10, G1–G12, P1–P5) and every
clean-code F1–F23 to a numbered epic; nothing in the language lens is
dropped to an "out of scope" bucket. The phase-0/4/5 split is sound and
sequencing (E02 pin → E16 bump → E17 idiom polish) is the correct
ordering. The deferral of 8.4-only wins (G1 asymmetric visibility, G2
Randomizer, G3 property hooks, G6 `#[\Deprecated]`, G7 lazy objects, G8
`#[\SensitiveParameter]`, G10 `mb_trim`) behind the `require.php` bump
is correctly modelled.

## Strengths
- **E02 pins phpVersion=80300 today** — line 168: `phpstan.neon ->
  parameters.phpVersion: 80300` and `phpcs.xml -> php_version=80300`.
  This is the single highest-leverage 8.3 fix and the plan front-loads it
  as a Phase-0 unblocker. Correct.
- **E16 is correctly gated behind `composer.json: "php": "^8.4"`** and
  bumps the analyser pins in lock-step (lines 817–819).
- **`final readonly` discipline is preserved** across every new shared
  class (AbstractDomainEvent, AbstractCommandHandler, LogSampler,
  ReadDTOInterface, MoneyFormatter, AggregateHydrator). The plan never
  introduces a mutable base.
- **E07 + E08 jointly close the handler boilerplate / 20-line cap**
  (F1+F3 from slice 14 + F3+F4+F11 from slice 03). The shared
  `LogSampler` using `random_int` lands in E05 *before* the 8.4
  Randomizer swap in E16 — correct staging.
- **typed const fix for `CookieName::MIN/MAX_LENGTH` (17/F1)** is folded
  into E09 (line "add typed const types (17/F1 fix here)") AND
  explicitly re-listed in E17, so it cannot be dropped between epics.
- **`#[\Override]` adoption (17/F3, ~25 sites) and `implements
  \Stringable` (17/F4) are correctly allocated to E17** as a Phase-5
  polish — they're 8.3-native and do NOT need the 8.4 bump.
- **Asymmetric visibility G1 is correctly noted as 8.4-only** (E16 line
  822: `public private(set) ?int $id`) and depends on E07 (which keeps
  `assignId/bumpVersion` discipline alive in the 8.3 interim).
- **ErrorCodes enum conversion (14/F4)** is staged correctly: shared
  `QueryLoggingLevel` enum in E05 (8.1+ feature), full `CookieErrorCode`
  conversion noted in E17. Not gated on 8.4.

## Required changes
1. **E02 must pin phpstan-bootstrap.php awareness.** The plan adds
   `parameters.phpVersion: 80300` but does not state that Slevomat
   sniffs `SlevomatCodingStandard.Classes.RequireConstantVisibility` and
   `SlevomatCodingStandard.Numbers.DisallowNumericLiteralSeparator` are
   version-bound. Add a sub-bullet: "Verify `phpcs.xml` enables
   `Generic.PHP.LowerCaseConstant` and that
   `SlevomatCodingStandard.TypeHints.PropertyTypeHint` is on; otherwise
   the typed-const-8.3 fix (17/F1) regresses silently." Without this the
   F1 fix is enforced by review only.
2. **E16 must list a Slevomat version-bump verification step.** Plan
   says "ensure property-hook / asymmetric-visibility sniffs". Slevomat
   `^8.15` (composer.json line 25) does NOT ship `private(set)` /
   property-hook sniffs reliably; needs `^8.18+`. Add an explicit
   composer bump line and a fallback: "if pinned Slevomat < 8.18, defer
   G1 / G3 enforcement to a follow-up". Otherwise CI can't catch
   regressions in the 8.4 features the epic adopts.
3. **G11 (DNF types) and G12 (implicit-nullable deprecation) are not
   explicitly allocated.** Slice 17 marked G12 as a "confirmation only"
   non-finding, but the plan should explicitly say so in the Matrix.
   G11 is listed under E16 but DNF type adoption (`string|(int&Stringable)`)
   is not mechanically enforceable without a Slevomat sniff. Add an
   acceptance gate to E17 or E18: "no `string|object` unions where
   `string|\Stringable` would suffice" or explicitly mark G11 as
   informational / not-actioned with a one-line rationale.

## Missing items
- **First-class callable adoption (17/F8, P1) is correctly in E17**, but
  the plan does not add a phpcs sniff to PREVENT regression. Add
  `SlevomatCodingStandard.Functions.UseFirstClassCallable` (available in
  recent Slevomat) to the E17 acceptance gate, otherwise the four
  `array_map(fn(...) => ...)` sites flagged in P1 will return on the
  first hand-written handler in the next domain clone.
- **`hrtime(true)` standardisation (17/P3, 03/F11, 14/F21)** is correctly
  spread across E05 (base) and E17 (polish), but the plan never says
  what unit the abstract base exposes — millisecond float? integer
  nanoseconds? Add to E05 acceptance: "`AbstractCommandHandler` /
  `AbstractQueryHandler` expose `private function elapsedMs(int
  $startNs): float` using `hrtime(true)`; no `microtime` calls remain in
  handler files".
- **`json_validate()` short-circuit (17/F9) and WeakMap cache (17/P5)**
  are allocated to E17 — correct PHP 8.3 native usage. But the plan
  should explicitly note these are 8.3+ features, not 8.4, so reviewers
  don't mis-allocate them to E16.
- **8.4 named-args-at-`>2`-args linting** is not addressed. Cookie uses
  named args correctly today (slice 14 line 203 praise), but there is
  no Slevomat sniff in the plan to enforce it as `/add-domain` clones.
  Add to E17: `SlevomatCodingStandard.Functions.RequireNamedArgsForArgsAt`
  if available; otherwise document the convention in E15 SKILL.md.
- **PHP 8.3 readonly-amends (`clone with`) is not flagged** — Cookie has
  no current need, but the new `AbstractDomainEvent` is readonly; if any
  caller wants an `occurredAt`-rewritten event for replay, the 8.3
  `clone with` syntax is the right idiom. Add a note to E04 acceptance:
  "AbstractDomainEvent is `final readonly`; rehydration uses `new`, not
  `clone with`, until 8.4 lazy proxies (G7) land."

## composer.json gate sequencing recommendation
Current: `"php": "^8.3"` (line 12) — caret admits 8.4 silently. The plan
correctly leaves this alone for Phase 0–3 and bumps in E16.

Recommended explicit gate sequence (add to plan):

1. **Phase 0 (E02, today):** keep `"php": "^8.3"`. Pin
   `phpstan.neon: phpVersion=80300` AND `phpcs.xml: php_version=80300`.
   Bump Slevomat to `^8.18` so 8.4-syntax sniffs exist as warnings (not
   errors) — defence in depth against an 8.4 dev host.
2. **Phase 1–3 (E04–E15):** no composer changes. All shared bases and
   refactors stay 8.3-compatible.
3. **Phase 4 (E16):** in a single PR — bump `"php": "^8.4"`, bump
   `phpstan.neon: phpVersion=80400`, bump
   `phpcs.xml: php_version=80400`, bump Slevomat to `^8.20+` (or
   whatever ships the property-hook sniff). Adopt G1/G2/G6/G8 in the
   same PR. G3 (property hooks), G7 (lazy proxies), G10 (`mb_trim`)
   can split to a follow-up if the diff exceeds 300 LoC.
4. **Phase 5 (E17/E18):** no composer changes; idiom polish and tests
   only.

The plan as written satisfies this implicitly but does not state the
Slevomat bump explicitly. Add the Slevomat constraint to E02 (today)
and E16 (bump-day) so the analyser-side of the pin doesn't lag the
language side.

## Bottom line
Top 3 required changes: (1) E02 must also bump Slevomat / verify typed-const
+ property-hook sniffs; (2) E16 must explicitly bump Slevomat to a version
shipping `private(set)` / property-hook sniffs or defer those wins;
(3) explicitly allocate G11 and G12 in the matrix and add first-class-callable
+ named-args Slevomat sniffs to E17 acceptance gates so the polish doesn't
regress on the next `/add-domain` clone.
