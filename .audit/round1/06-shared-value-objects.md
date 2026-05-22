# 06 — Shared value objects

Audit of `app/Domain/Shared/ValueObjects/*`. Foundational layer — bugs here propagate to every domain.

## Files audited

### Money

File: `app/Domain/Shared/ValueObjects/Money.php`

- **CRITICAL — Silent USD default `Money.php:36-40, 47, 58, 94`.** Constructor and every factory accept `?Currency = null` and fall back to `Currency::usd()`. A caller that forgets to pass a currency gets a USD-tagged value with no exception. For a multi-currency template this is a foot-gun: a BRL repository that omits the currency arg will silently mint USD. Make `Currency` required, or throw when omitted.
- **CRITICAL — Not JSON-serialisable round-trippably `Money.php:31-40`.** `$amountMinor` is `private`; `$currency` is `public`. `json_encode($money)` emits only `{"currency":{...}}` — the amount is lost. No `JsonSerializable`, no `toArray()`. Wire payloads and event envelopes will silently drop the amount.
- **HIGH — Integer overflow on parse `Money.php:77-83`.** `((int) $major) * $factor + (int) $minorPadded` has no bound check. On 64-bit, a 19-digit `$major` with `$factor = 1000` (BHD) overflows silently — PHP coerces to float, then `(int)` truncates non-deterministically. Reject inputs whose magnitude exceeds `PHP_INT_MAX / $factor` before multiplying.
- **HIGH — Arithmetic overflow uncaught `Money.php:158-173`.** `add`, `subtract`, `multiply` perform raw `int` ops with no overflow guard. PHP silently promotes to float on overflow → loss of precision in a money type. At minimum, detect with `is_int($result)` post-op or pre-compute against `PHP_INT_MAX`.
- **HIGH — `fromFloat` overflow `Money.php:99-101`.** `(int) round($value * $factor)`: `round()` returns `float`; large inputs cast to int with platform-dependent results. No bound check beyond `is_finite`. Documented as last-resort but still unsafe.
- **MEDIUM — Currency-symbol strip is partial `Money.php:204`.** Pattern `/^[\$£€¥]\s*/u` strips only four symbols. `R$`, `kr`, `₹`, `C$` survive and then fail the numeric regex. Either accept the full symbol set used in `Currency::defaultSymbolFor` or do not strip at all (force callers to pass clean numerics).
- **MEDIUM — `equals` cross-currency returns false rather than throwing `Money.php:140-144`.** Defensible, but inconsistent with `greaterThan`/`lessThan` which throw on currency mismatch (`Money.php:148, 154`). Pick one model; mixing "false vs throw" leads to subtle bugs in callers that compare without same-currency precondition.
- **LOW — No `isPositive`, `negate`, `abs`, `divide`, `allocate` (proportional split) `Money.php:130-173`.** Standard money helpers absent; downstream domains will re-implement.
- **LOW — `multiply(int)` only `Money.php:170-173`.** No decimal/percentage multiply path with explicit rounding mode. Tax/discount math will be re-invented per domain.
- **LOW — `format()` symbol placement is currency-blind `Money.php:118-123`.** Always prefix; EUR convention is suffix in many locales. Acceptable for a template if documented.

### Currency

File: `app/Domain/Shared/ValueObjects/Currency.php`

- **MEDIUM — ISO-4217 validation is shape-only `Currency.php:44-52`.** Regex `/^[A-Z]{3}$/` accepts `ZZZ`, `XXX`, `AAA` — non-existent codes. For a "canonical" VO, validate against the ISO-4217 active set (or document explicitly that validation is shape-only).
- **MEDIUM — 4-decimal currencies absent `Currency.php:25-35`.** ISO-4217 lists 4-decimal codes (`CLF`, `UYW`). They default to 2 here, producing wrong minor-unit precision. Add overrides or document the gap.
- **LOW — Caller-supplied `$symbol` not validated `Currency.php:44, 57`.** Any string accepted (including newlines, HTML). Risk is display-layer XSS if the value is rendered unescaped; not a VO concern strictly but worth a length+printable check.
- **LOW — No `JsonSerializable`/`fromArray` `Currency.php:19-94`.** `json_encode` works incidentally (public readonly props), but no canonical reconstitution path.
- **LOW — `equals` compares ISO only `Currency.php:76-79`.** Correct (decimals/symbol are derived) but undocumented; a future caller who hand-constructs via private ctor reflection would be surprised.

### Actor

File: `app/Domain/Shared/ValueObjects/Actor.php`

- **HIGH — `system($label)` accepts arbitrary input `Actor.php:36-39`.** No length cap, no newline check, no charset whitelist. `Actor::system("admin\ninjection: forged-line")` flows straight into audit logs. Validate: non-empty, max ~64 chars, `[a-z0-9_:.-]+` or similar; this is a log-injection vector.
- **MEDIUM — No `equals()` `Actor.php:15-45`.** Inconsistent with `Money`, `Currency`, `Email`, `Permission`. Two `Actor` instances representing the same user must be compared via `$a->id === $b->id`, leaking the field.
- **MEDIUM — No `__toString()` `Actor.php:15-45`.** Other VOs (`Email`, `Permission`, `DocumentNumber`) implement it; absence is inconsistent and makes log/error messages awkward.
- **LOW — `SYSTEM_ID = 0` collides conceptually with "no user" `Actor.php:17, 26-31`.** `Actor::user(0)` throws but `Actor::system()` always returns id 0. A reader of `actor.id == 0` cannot tell which. Consider negative sentinel or distinct nullable id.
- **LOW — No `isUser()` complement to `isSystem()` `Actor.php:41-44`.** Minor.

### Permission

File: `app/Domain/Shared/ValueObjects/Permission.php`

- **MEDIUM — No length cap `Permission.php:25-37`.** Regex is unbounded; `Permission::fromString(str_repeat('a', 1_000_000) . '.x')` is accepted. Cap segment length (e.g. ≤ 64 each).
- **LOW — Two-segment only, by design `Permission.php:28`.** Acceptable per docblock, but no helper for hierarchical checks (`cookies.*`). Domains will likely reinvent.
- **LOW — No `JsonSerializable` `Permission.php:16-48`.** Public readonly props serialise OK, but no `fromArray`/round-trip helper.

### DocumentNumber

File: `app/Domain/Shared/ValueObjects/DocumentNumber.php`

- **CRITICAL — `public` constructor with zero validation `DocumentNumber.php:20-26`.** Any caller can `new DocumentNumber('', '', -1, '')`. Breaks the VO invariant. The class docblock promises a "formatted, human-readable identifier produced by the DocumentNumberingService" — but nothing enforces that producer. Make ctor private and expose a `fromService(...)` named factory; validate non-empty `series`, non-empty `scope`, `value >= 0`, `formatted` matches expected shape.
- **HIGH — No invariant tying `value` to `formatted` `DocumentNumber.php:22-25`.** A caller can pass `value: 42, formatted: 'INV-2026-99999'`. The two fields can disagree and no check catches it.
- **MEDIUM — No `equals()` `DocumentNumber.php:18-32`.** Inconsistent with peer VOs.
- **LOW — `value: int` for sequence `DocumentNumber.php:23`.** PHP int — sufficient for any realistic sequence, but no upper bound stated.
- **LOW — `__toString` stable `DocumentNumber.php:28-31`.** Returns `$formatted` directly. Fine — but stability depends on the (uninvalidated) constructor input. Once the ctor is locked down, this is correct.

### Email

File: `app/Domain/Shared/ValueObjects/Email.php`

- **MEDIUM — No length cap `Email.php:45-54`.** RFC 5321 caps the address at 254 octets (local ≤ 64, domain ≤ 253). `FILTER_VALIDATE_EMAIL` does not enforce. Long inputs reach storage / log writers.
- **MEDIUM — Local-part case lost on normalisation `Email.php:47`.** `strtolower($email)` lowercases the local part too; RFC 5321 says local parts are case-sensitive at the recipient. Pragmatic for dedup, but document the trade-off (`"User@x"` and `"user@x"` collide).
- **LOW — `filter_var` rejects some RFC 5322-valid quoted-local forms `Email.php:49`.** Acceptable — docblock says "RFC standards" loosely; tighten or reword.
- **LOW — `getDomain`/`getLocalPart` return `''` on impossible failure `Email.php:84-90, 99-105`.** Since the value passed validation, `strpos` cannot be `false`; the fallback `return ''` is dead code, but it makes the contract weaker than it could be (`assert($atPosition !== false)` would be clearer).
- **LOW — No `JsonSerializable` `Email.php:32-126`.** `__toString` works, but no array round-trip.
- **LOW — No IDN/punycode handling `Email.php:49`.** ASCII-only domains. Document.

### DateTimeValue

File: `app/Domain/Shared/ValueObjects/DateTimeValue.php`

- **CRITICAL — Implicit server timezone `DateTimeValue.php:54-57, 65-74`.** `new DateTimeImmutable()` and `createFromFormat('Y-m-d H:i:s', $datetime)` both use PHP's default timezone (`date.timezone` / `date_default_timezone_get()`). The same string deserialised on a UTC server and a `America/Sao_Paulo` server produces different absolute instants. For audit logs, events, and DB timestamps this is a correctness bug. Force UTC: `new DateTimeImmutable('now', new DateTimeZone('UTC'))` and pass `DateTimeZone('UTC')` to `createFromFormat`.
- **HIGH — `equals()` uses identity `===` on objects `DateTimeValue.php:116-119`.** `$this->value === $other->value` is true only when both refer to the same `DateTimeImmutable` instance. Two equal-but-distinct instants (the common case) return `false`. Use `==` (PHP compares timestamps) or `$this->value->getTimestamp() === $other->value->getTimestamp() && tz match`.
- **HIGH — `fromString` accepts rollover dates silently `DateTimeValue.php:65-74`.** `createFromFormat('Y-m-d H:i:s', '2025-02-30 00:00:00')` returns a valid `DateTimeImmutable` (March 2). No `getLastErrors()` check. The VO claims validation but lets bogus dates through.
- **MEDIUM — `__toString` and default `format()` discard timezone `DateTimeValue.php:105-108, 147-150`.** Round-trip via `fromString(__toString($d))` loses TZ even when present. Provide an `Y-m-d\TH:i:sP` (ISO-8601) default or a separate `toIso8601()`.
- **MEDIUM — Only one input format supported `DateTimeValue.php:67`.** No ISO-8601, no Unix timestamp. HTTP/JSON layers will struggle. Add `fromIso8601(string)`, `fromTimestamp(int)`.
- **LOW — No `JsonSerializable` `DateTimeValue.php:33-151`.** `__toString` fills the gap loosely; explicit serialisation contract would be clearer.
- **LOW — No arithmetic helpers `DateTimeValue.php:33-151`.** No `addDays`, `diffInDays`. Optional but every domain will re-implement.

### AttachmentRef

File: `app/Domain/Shared/ValueObjects/AttachmentRef.php`

- **HIGH — `public` constructor, no validation `AttachmentRef.php:17-27`.** Same defect class as `DocumentNumber`. Negative `id`, empty `originalName`, blank `mimeType`, negative `sizeBytes`, malformed `checksumSha256` all accepted. Make ctor private; add `fromRow(array)` / `fromUpload(...)` factories with checks (`id > 0`, non-empty `attachableType`/`attachableId`/`originalName`/`mimeType`, `sizeBytes >= 0`, checksum matches `/^[a-f0-9]{64}$/` when non-null, `uploadedBy >= 0`).
- **MEDIUM — `attachableType` not constrained `AttachmentRef.php:19`.** A free-form string here defeats the type-safety goal. Either a sealed enum/value object, or at minimum a regex (FQCN-like `App\\Domain\\.+`).
- **MEDIUM — No `equals()` `AttachmentRef.php:15-28`.** Inconsistent with peers.
- **LOW — No `__toString` `AttachmentRef.php:15-28`.** Minor; not always meaningful for a file.
- **LOW — Verified: no storage key/disk field `AttachmentRef.php:17-27`.** Matches the stated "domain-safe view" intent — infra-only data correctly absent.

## Cross-cutting findings

- **CRITICAL — Constructor discipline inconsistent.** `Money`, `Currency`, `Actor`, `Permission`, `Email`, `DateTimeValue` use `private __construct` + named static factories. `DocumentNumber` (`DocumentNumber.php:20`) and `AttachmentRef` (`AttachmentRef.php:17`) use `public __construct` with no validation. The two public-ctor VOs are the ones most exposed to bypassed invariants.
- **CRITICAL — No canonical JSON serialisation.** No VO implements `JsonSerializable`. `Money` in particular drops `amountMinor` from `json_encode` output because the field is `private` while `currency` is `public` (`Money.php:33-34`). Event payloads, log context arrays, HTTP responses are all affected. Either:
  1. Implement `JsonSerializable` on every VO with a stable shape, plus a `fromArray()` reconstitution factory, or
  2. Standardise on `toArray()` + `fromArray()` and forbid direct `json_encode` of VOs.
- **HIGH — `equals()` missing on 3 of 8 VOs.** `Actor`, `DocumentNumber`, `AttachmentRef` lack it; others have it. Anyone writing collection de-dup or assertion helpers must special-case three types.
- **HIGH — Timezone story absent system-wide.** Only `DateTimeValue` deals with time, and it punts to PHP defaults (`DateTimeValue.php:56, 67`). Need a single project-wide rule (UTC at the domain boundary, presentation conversion elsewhere) plus enforcement in this VO.
- **MEDIUM — `__toString` coverage inconsistent.** Present on `Money`, `Permission`, `Email`, `DateTimeValue`, `DocumentNumber`; absent on `Currency`, `Actor`, `AttachmentRef`. Log/error-message ergonomics suffer.
- **MEDIUM — Validation depth varies wildly.** `Email`, `Permission`, `Money` validate aggressively. `DocumentNumber`, `AttachmentRef` do not validate at all. `Actor::system` accepts arbitrary strings. Adopt a uniform rule: every VO validates every invariant at construction, full stop.
- **MEDIUM — No equality contract for hashing/dedup.** No `hashCode`/canonical key method anywhere. Using VOs as array keys requires `(string)` coercion which is only defined on some.
- **MEDIUM — No tests visible for the new VOs in this folder.** (Out of scope to enumerate here, but worth a follow-up — `Money::fromDecimalString` overflow, `DateTimeValue::equals` identity bug, and `Actor::system` injection are all currently silently broken.)
- **LOW — Mix of inline `public` property promotion vs. private + assignment.** `Currency`, `Actor`, `Permission`, `DocumentNumber`, `AttachmentRef` use promoted public readonly. `Money`, `Email`, `DateTimeValue` use private + assignment. Both are correct PHP 8.4; pick one for consistency.
- **LOW — Default-USD pattern is unique to `Money`.** No other VO smuggles in an implicit default for a required dependency. Aligning with the rest of the layer (always-required) is the simpler rule.

## Verdict

**FAIL — block downstream domain work until fixes land.** Four CRITICAL issues affect the foundation that every other domain inherits:

1. `Money` defaults silently to USD on omission and cannot be round-tripped via `json_encode`.
2. `DocumentNumber` and `AttachmentRef` are constructable in invalid states by any caller.
3. `DateTimeValue` operates in the server's local timezone and its `equals` is broken (object-identity check).

The HIGH issues (`Actor::system` log-injection surface, `Money` arithmetic overflow, `DateTimeValue::fromString` accepts 2025-02-30, missing `equals()` on three VOs, integer-overflow on `fromDecimalString`) are real-world bugs waiting for the first non-trivial production data.

Recommended sequencing: fix CRITICAL + HIGH together (they cluster around the same root cause: under-enforced invariants and inconsistent ceremony across VOs). Establish a "Shared VO checklist" — private ctor, validate everything, `equals`, `__toString`, `JsonSerializable`, UTC-only time — and reapply across all eight files before any further domain scaffolding consumes them.
