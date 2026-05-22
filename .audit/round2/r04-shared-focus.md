# Round 2 — Shared layer review (VOs, Exceptions, AggregateRoot, StateMachine)

Scope: verify findings in `.audit/round1/06-shared-value-objects.md` and
`.audit/round1/07-shared-exceptions-aggregateroot-statemachine.md` against the
actual files. All experiments were executed locally with PHP 8.4.

---

## Verified findings (with extra evidence)

### V1 — Money silent USD default — VERIFIED, but it is **usability/footgun**, not a security issue

Reproduction:

```
$silentDefault = Money::fromMinorUnits(100);
$silentDefault->currency->iso  // → "USD"
```

This is a real foot-gun. The single consumer in tree (`CookiePrice` at
`app/Domain/Cookie/ValueObjects/CookiePrice.php:73,85,96`) **always** passes
a currency (it forwards `self::defaultCurrency()`), so the live blast
radius today is zero. The risk is for cloned domains: a future
`InvoiceLine`, `OrderTotal`, or `ProductPrice` that mirrors Cookie's
pattern but forgets to wire `defaultCurrency()` will silently mint USD
rows on a BRL deployment.

Classification: **usability / latent correctness**, not security. There is
no attack vector — a malicious user cannot influence the default. But the
defect will become a silent data bug the moment a non-Cookie domain is
scaffolded against this VO, which is precisely the template's purpose.
Severity HIGH is more accurate than CRITICAL.

Consumers that would get bitten: every future call site that omits the
currency arg. Today: `Money::fromMinorUnits(299)` in
`tests/Unit/Domain/Shared/ValueObjects/MoneyTest.php:25` is the only
omitting site in the repo, and the test happens to assert USD — so even
the test treats the default as a feature.

### V2 — Money `json_encode` drops `amountMinor` — VERIFIED, CRITICAL stands

Mental walk-through matches reality. `$amountMinor` is `private` (line 34),
`$currency` is `public readonly` (line 33). PHP's default object → JSON
serialiser exposes only public properties.

Live confirmation:

```
$m = Money::fromMinorUnits(299, Currency::usd());
echo json_encode($m);
// → {"currency":{"iso":"USD","decimals":2,"symbol":"$"}}
// amountMinor is gone.
```

`$m instanceof JsonSerializable` is `false`. No `toArray()`. No
`fromArray()`. Every event payload, every log-context array, every HTTP
response that hands a `Money` to `json_encode` drops the amount and
silently ships only the currency. The audit's CRITICAL severity is
correct — this is a data-loss bug that survives Level-8 PHPStan and
all existing tests because no test asserts the JSON shape.

Aggravating fact: `Money::__toString()` exists and returns the decimal
amount only (no currency). So neither `json_encode($m)` nor `(string)$m`
round-trips both fields. There is no canonical wire form.

### V3 — DocumentNumber / AttachmentRef public ctors — VERIFIED, CRITICAL stands

Both classes use `public function __construct(...)` with **zero** validation
in the body. Anything goes:

```
new DocumentNumber("", "", -1, "");      // accepted; formatted="", value=-1
new AttachmentRef(-1, "", "", "", "", -5, "garbage", -1); // accepted
```

What a caller can break:

- **DocumentNumber:** the docblock promises "produced by the
  `DocumentNumberingService`". Nothing enforces that producer. Any
  consumer wanting "the document this invoice is bound to" can be handed
  an empty string and will print "Invoice " in templates. Comparison by
  `$a->value === $b->value` will collide multiple `value=-1` "documents".
  Worse: the `value` (raw int) and the `formatted` (string) can disagree
  with no check, so `DocumentNumber("INV", "year-2026", 42,
  "INV-2026-99999")` is constructible and undetectable downstream.
- **AttachmentRef:** negative `id` / `sizeBytes`, malformed
  `checksumSha256` (not `[a-f0-9]{64}`), empty `attachableType` /
  `attachableId` / `originalName` / `mimeType` all pass. Storage code
  downstream may treat `sizeBytes < 0` as a flag or compute negative
  quotas; checksum verification will fail silently if it just checks
  "non-null".

Severity CRITICAL stands. These are the two VOs that pretend to be VOs
without actually being VOs.

### V4 — DateTimeValue server-timezone + `equals` identity bug + rollover dates — VERIFIED

All three sub-issues reproduce:

```
$d = DateTimeValue::fromString("2025-02-30 00:00:00");
$d->format("Y-m-d H:i:s");          // → "2025-03-02 00:00:00"

$a = DateTimeValue::fromString("2025-01-01 10:00:00");
$b = DateTimeValue::fromString("2025-01-01 10:00:00");
$a->equals($b);                      // → false (two distinct instances)
```

The timezone bug isn't visible from one machine but is dangerous as a
template — `new DateTimeImmutable('now')` and `createFromFormat('Y-m-d
H:i:s', ...)` both use `date.timezone`. CRITICAL is appropriate for a
template that will be deployed across regions.

### V5 — Error-code collision Cookie 101 vs User 101 — VERIFIED

Direct read of both files:

- `app/Domain/Cookie/ErrorCodes.php:28` →
  `COOKIE_VALIDATION_NAME = 101`
- `app/Domain/User/ErrorCodes.php:21` →
  `USER_VALIDATION_EMAIL = 101`

Both are plain `const int` on a `final class`, namespaced separately, so
PHP itself doesn't care — but any aggregator that bins by numeric code
(monitoring, alert rules, log dashboards) cannot tell `101` apart without
also reading the channel/domain field. The duplicate constant inside
User (`LOCKED = 301`, `ACCOUNT_LOCKED = 301` at
`User/ErrorCodes.php:32-33`) — verified — adds aliasing-without-enum
drift on top.

### V6 — `Actor::system($label)` log-injection surface — VERIFIED

```
$a = Actor::system("admin\ninjection: forged-line");
echo $a->label;
// → "admin
//    injection: forged-line"
```

Newlines pass through. There is no charset whitelist, no length cap. If
this label reaches a line-oriented log (which is the system's default for
the file backends), an attacker who controls the label can forge log
entries. The label is currently `'system'` by default and call sites
mostly pass static strings — but the audit's wording (`HIGH`) is accurate
because the API allows arbitrary strings with zero protection.

### V7 — AggregateRoot adoption inconsistency in Cookie — VERIFIED, accurate listing

Cross-checked `Cookie.php` against the audit's claim:

- `decreaseStock()` (`Cookie.php:236`) raises `CookieStockChangedEvent` ✓
- `increaseStock()` (`Cookie.php:259`) raises `CookieStockChangedEvent` ✓
- `update()` (`Cookie.php:195-207`) — **no event raised**. Calls
  `$this->setStock($stock)` which is a private helper that also raises
  nothing. So calling `update()` to change stock from 50 → 10 emits NO
  `CookieStockChangedEvent`, while calling `decreaseStock(40)` does.
  Confirmed.
- `activate()` (`Cookie.php:288`) / `deactivate()` (`Cookie.php:297`) —
  silent state flips, no event. Confirmed.
- `create()` (`Cookie.php:107-115`) — no `CookieCreated` event raised.
  Confirmed.

The projection (`CookieReadModelProjection`) only listens for
`CookieStockChangedEvent`, so the read model misses every create / update
/ activate / deactivate even if those events existed. (Cross-references
report 04 territory; flagging because it amplifies this finding.)

### V8 — StateMachine and Exception findings — VERIFIED at the structural level

Confirmed from direct read of the four files:

- `DomainException extends RuntimeException`, `ValidationException extends
  InvalidArgumentException` — no shared interface (`DomainException.php:36`,
  `ValidationException.php:32`). Catching "any domain fault" requires
  listing both base types.
- No `InfrastructureException` exists anywhere in the codebase
  (`grep -r "InfrastructureException" app/` → empty).
- `$errorCode = 0` defaults across every factory, and
  `parent::__construct($message, $code)` passes the *PHP* code (always 0
  in practice). `getCode()` and `getErrorCode()` therefore return
  different ints — `getCode()` is always 0. Audit MEDIUM is accurate.
- `StateMachine` is stringly-typed; no construction-time validator;
  `isTerminal` returns `true` for unknown states (`allowedFrom` falls
  through `?? []`). Confirmed.
- `InvalidTransition::create` does not accept an `$errorCode`. Confirmed.

---

## Disputed / nuanced findings

### D1 — "Money arithmetic silently promotes int → float on overflow" — PARTIALLY DISPUTED

Audit (`06-shared-value-objects.md:14`) says `add/subtract/multiply` *silently*
promote to float. With `strict_types=1` and the ctor signature
`__construct(int $amountMinor, ...)`, that's not what happens:

```
$max = Money::fromMinorUnits(PHP_INT_MAX);
$max->multiply(2);
// TypeError: Argument #1 ($amountMinor) must be of type int, float given
```

Same for `add()` past `PHP_INT_MAX`. The TypeError is loud, not silent —
the audit's "silent promotion" framing is wrong. The bug is real (no
graceful handling, no domain exception, just a TypeError that bubbles
up), but the failure mode is different from what the audit describes,
and the severity assessment should account for that. Recommend rewording
the finding: "arithmetic overflow throws an unhandled TypeError instead
of a `DomainException::overflow`" — same fix, accurate description.

`Money::fromFloat` IS silent though: `(int) round($value * $factor)` does
the cast before the ctor sees it, so `Money::fromFloat(1e20)` returns a
real `Money` instance with `amountMinor() = 1864712049423024128` (an
arbitrary truncation of 1e20). Audit `HIGH` stands for `fromFloat`
specifically.

`fromDecimalString` similarly: `((int) $major) * $factor` casts before
the multiply; the multiply happens on ints then the result goes to the
ctor. If `(int) $major` overflows during the cast, it silently saturates
to PHP_INT_MAX. So the path here is also silent → bad, but the failure
point is the cast, not the multiplication. Same fix, slightly different
mechanism.

### D2 — "Money silent USD default is CRITICAL" — DISPUTED severity

See V1. The behaviour is real but classifying it CRITICAL conflates a
template foot-gun with a live data bug. Today's only caller (Cookie)
always passes a currency. Recommend HIGH.

### D3 — "Domain depends on Infrastructure — also true for Shared?" — **DISPUTED for Shared**

`grep -r "App\\Infrastructure\|CodeIgniter\\" app/Domain/Shared/` →
empty. Every `use` statement in the Shared layer is intra-Shared:

```
StateMachine/InvalidTransition.php  → uses Shared\Exceptions\DomainException
ValueObjects/Email.php              → uses Shared\Exceptions\ValidationException
ValueObjects/DateTimeValue.php      → uses Shared\Exceptions\ValidationException
ValueObjects/Money.php              → uses Shared\Exceptions\ValidationException
```

The Shared layer is clean. The Domain→Infrastructure inversion called out
in report 08 (`User/Ports/RateLimitInterface.php` importing
`Infrastructure\Auth\ValueObjects\RateLimitResult`) is a User-domain
concern, not a Shared concern. The Shared layer itself respects the
hexagonal arrow.

### D4 — "MEDIUM: `equals` cross-currency returns false rather than throwing" — DISPUTED design

The audit flags `Money::equals` as inconsistent with `greaterThan` /
`lessThan` (which throw on currency mismatch). I disagree. "Equality"
semantically must be total — `$a->equals($b)` should answer for any pair
of Money values, including cross-currency. `>` and `<` are partial
orderings that genuinely don't make sense across currencies, so throwing
there is right. The current asymmetry is correct, not a bug. Recommend
this finding be downgraded to "documentation: explain why" or dropped.

### D5 — "MEDIUM: no `tooLarge` factory" — DISPUTED necessity

`ValidationException::outOfRange(field, min, max, actual)` already covers
the upper-bound case. `tooLarge` is convenience syntax. Recommend LOW.
The missing `custom(field, message)` factory (audit MEDIUM) is genuinely
needed; that one stands.

---

## New shared-file findings the audit missed

### N1 — HIGH — `Money::add`/`subtract`/`multiply` on a USD-defaulted value cross with a properly-currencied value silently

If a caller does `Money::fromMinorUnits(100)->add(Money::fromMinorUnits(50,
Currency::brl()))`, the result is a `Money` of `R$ 150` — because
`assertSameCurrency` runs before the add, it throws on the mismatch.
But the reverse `Money::fromMinorUnits(100, Currency::brl())->add(
Money::fromMinorUnits(50))` *also* throws — the silent-USD on the
right-hand side makes a cross-currency add visible as a cross-currency
error rather than as the actual root cause ("you forgot the currency").
The error message ("Cannot mix currencies: BRL vs USD") is misleading.
Worth wiring this fix into the V1 USD-default fix.

### N2 — HIGH — `DocumentNumber` is `readonly` but mutable through assignment in older PHP if `readonly` is removed

Not a current bug — but the class is one annotation away from being
mutable. Combined with the public ctor (V3), nothing protects the
invariant. Defence-in-depth: even with `final readonly`, a private ctor
makes invariant violation impossible. Today it is merely improbable.

### N3 — MEDIUM — `AggregateRoot::raiseEvent(object $event)` accepts ANY object

Audit calls this out as MEDIUM and proposes `DomainEventInterface`.
What it misses: `peekEvents(): list<object>` cannot be typed precisely
either, so consumers (the dispatcher, tests, projections) can't rely on
a contract. `CookieReadModelProjection.php:69` already uses
`$event instanceof CookieStockChangedEvent` which works, but a typo'd
class name in a `raiseEvent(new CookieStokChangedEvent(...))` (no
constructor at all) would still type-check at PHPStan Level 8 because
`object` accepts everything.

### N4 — MEDIUM — `Permission::fromString` allows 100KB segment names

```
$p = Permission::fromString(str_repeat("a", 100000) . ".x");
strlen($p->name);  // → 100002 (accepted)
```

Audit flagged this but its severity (MEDIUM) is correct only because the
template is small today. Permissions are typically log/audit fields and
will end up in DB columns sized at 255 or less. A migration that adds a
`VARCHAR(255)` constraint on `permissions.name` will silently truncate
or hard-fail INSERTs against any Permission that exceeds the cap.

### N5 — LOW — `ValidationException::withErrors` rebuilds message from `array_keys` & `array_sum(array_map('count', $errors))`

If the input is `['email' => ['format', 'unique']]` the message is
"Validation failed for field(s): email (2 error(s))" — good. But the
array shape isn't validated. Passing `['email' => 'format']` (string
instead of `array<string>`) yields a TypeError from `count('format')` in
PHP 8.4. Acceptable, but the factory advertises a tolerant API while
silently requiring the strict shape. PHPStan-level safety net only —
runtime callers get a crash, not a useful exception.

### N6 — LOW — `Currency::usd()`, `eur()`, `brl()` are convenience factories; no `Currency::default()`

Means there's no single source of truth for "what currency does this
deployment use by default". The hardcoded `Currency::usd()` fallback in
`Money` (V1) is the de-facto answer. A `Currency::default()` reading a
config (or an injected default) would make the system multi-tenant /
multi-region friendly and would let V1 be fixed without breaking the
test suite.

### N7 — MEDIUM — `Email::__construct` lowercases the local part, but no length cap

Audit caught both as separate MEDIUMs. What it missed: the combination
means an attacker who can sign up with `aaaa…(64+ chars)…@x` produces an
Email that satisfies `filter_var` but violates RFC 5321 octet caps
(local ≤ 64, total ≤ 254). Downstream code (DB `VARCHAR(255)`, SMTP)
rejects it. The VO promises "validated" but isn't.

### N8 — LOW — `DateTimeValue::fromDateTime(DateTimeInterface)` doesn't normalise to UTC either

Even if V4 is fixed for `now()` and `fromString()`, `fromDateTime()`
accepts whatever timezone the input carries. The VO has no canonical
internal timezone. A repository reading a UTC column and a controller
parsing a `+05:30` HTTP header both produce valid `DateTimeValue` objects
that compare unequally on otherwise-identical instants.

---

## Verdict

The two round-1 reports are **substantially accurate**. Of seven items I
verified empirically:

- 5 are confirmed exactly as described (V2, V3, V4, V5, V6, V7, V8 — 7
  actually).
- 1 (V1, silent USD default) is real but mis-classified CRITICAL —
  should be HIGH.
- 1 (D1, "silent arithmetic overflow") describes the right defect with
  the wrong mechanism: `strict_types` makes `add/subtract/multiply`
  throw TypeError loudly, not silently. `fromFloat` and the cast inside
  `fromDecimalString` are the actual silent paths.

The disputed items (D2-D5) are minor framing / severity / design choices,
not factual errors.

The "Domain depends on Infrastructure" cross-layer concern raised in
report 08 does **not** apply to the Shared layer. Shared imports nothing
outside its own subtree (verified via grep).

Eight new findings (N1-N8) are not show-stoppers individually but
reinforce the round-1 verdict: the Shared layer is **structurally sound,
practically under-enforced**. The two public-ctor VOs (DocumentNumber,
AttachmentRef) and the JSON-broken Money are the only items that I would
genuinely block the next domain scaffold on. Everything else is fixable
incrementally without blocking forward work.

**Final verdict: round-1 audits are accepted with the noted corrections.**
The shared layer needs three blockers fixed before further domain work:

1. Lock down `DocumentNumber` and `AttachmentRef` (private ctor + factories
   + validation).
2. Implement `JsonSerializable` + `fromArray` on `Money` (and ideally
   every other VO) so the wire form round-trips.
3. Force UTC and fix `equals` in `DateTimeValue`.

The error-code registry collision (V5) and the AggregateRoot adoption
gap in Cookie (V7) are the next-most-impactful items — both block any
new domain that uses the scaffolding skill, because the skill currently
templates the same patterns.
