# 13 — HTTP / Bulk CSV / Logging

## Files audited
- app/Infrastructure/Http/ApiResponse.php
- app/Infrastructure/Http/Client/HttpResponse.php
- app/Infrastructure/Http/Client/HttpTransportInterface.php
- app/Infrastructure/Http/Client/CurlHttpTransport.php
- app/Infrastructure/Http/Client/HttpException.php
- app/Infrastructure/Http/Client/OutboundHttpClient.php
- app/Infrastructure/Http/Middleware/IdempotencyMiddleware.php
- app/Infrastructure/Bulk/CsvReader.php
- app/Infrastructure/Bulk/CsvWriter.php
- app/Infrastructure/Bulk/BulkImportInterface.php
- app/Infrastructure/Bulk/BulkImportRunner.php
- app/Infrastructure/Bulk/ImportSummary.php
- app/Infrastructure/Logging/LoggerFactory.php
- app/Infrastructure/Logging/LoggingServiceProvider.php
- app/Infrastructure/Logging/CorrelationIdService.php
- app/Infrastructure/Logging/CorrelationIdMiddleware.php
- app/Infrastructure/Logging/DomainLogger.php
- app/Infrastructure/Logging/RedactingProcessor.php

## Findings

### ApiResponse

- **LOW** — ApiResponse.php:60,93 — Calling `CorrelationIdService::get()` on the success path lazily generates a UUID if no middleware ran. Fine in HTTP context (CorrelationIdMiddleware forces generation), but in console/CLI rendering it silently mints a one-off id with no correlation to anything. Acceptable, but worth a note in the docblock.
- **LOW** — ApiResponse.php:55,69,83 — Success envelope nests `correlation_id` under `meta`, but problem+json (line 128) puts it at the top level. Inconsistent shape for clients; documented in the docblock (lines 14–44), so intentional but worth flagging.
- **INFO** — ApiResponse.php:139–144 — Content-type chain `setStatusCode → setJSON → setContentType('application/problem+json')` is correct: `setJSON` always sets `application/json`, and the trailing `setContentType` overrides it. Comment is accurate.
- **MEDIUM** — ApiResponse.php:107–110 — `noContent()` returns a 204 with NO correlation id header. Since this bypasses the envelope, the only way to attach correlation id is via CorrelationIdMiddleware::after (which does set `X-Correlation-Id`). OK in practice but means 204 responses are the only ones without an in-body id, mildly inconsistent with the documented contract.

### HttpResponse / HttpTransportInterface / HttpException

- Clean. HttpResponse:43 lower-cases header lookup; matches CurlHttpTransport's parsing at line 98. No issues.

### CurlHttpTransport

- **HIGH** — CurlHttpTransport.php:40 — `CURLOPT_FOLLOWLOCATION = false` means 3xx redirects surface as redirect responses to the caller. OutboundHttpClient does NOT include 3xx in `retryStatuses` and treats them as terminal "success" (not 2xx, not retried, not raised). `getJson()` calls `ensureSuccessful()` (line 199 in OutboundHttpClient) which rejects 3xx — but `postJson()` and `request()` callers receive a 3xx response with no body decoding, silently. Document or auto-follow safe (GET/HEAD) redirects.
- **MEDIUM** — CurlHttpTransport.php:58 — `curl_getinfo(..., CURLINFO_RESPONSE_CODE)` returns `int|false`; on a successful exec it'll be an int, but PHPStan strict would flag the implicit `int` cast into `HttpResponse::$statusCode`. No runtime bug but `(int)` cast at line 57 would be defensive.
- **MEDIUM** — CurlHttpTransport.php:58–66 — When a server returns intermediate `100 Continue` or proxy `CONNECT` responses, cURL concatenates multiple header blocks into the header section. `parseHeaders` will only see the LAST block by overwriting, which is correct, but `$headerSize` covers ALL header blocks, so body split is still correct. OK.
- **LOW** — CurlHttpTransport.php:42 — Connect timeout caps at `min(timeout, 10)`. Hardcoded ceiling is a magic number and not documented in the constructor signature; expose via constructor for environments with high-latency hops.
- **MEDIUM** — CurlHttpTransport.php:43 — No `CURLOPT_PROTOCOLS` restriction. A caller-controlled URL can point to `file://`, `gopher://`, `dict://`, etc. SSRF surface: anywhere `OutboundHttpClient` is called with externally-influenced URLs becomes a vector. Add `curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS)`.
- **PASS** — CurlHttpTransport.php:47–48 — SSL verification (peer + host) explicitly enabled. Good.

### OutboundHttpClient

- **CRITICAL** — OutboundHttpClient.php:154–156 — Auto Idempotency-Key generation is applied to **ALL** mutating methods (POST/PUT/PATCH/DELETE) for ALL retries, including the retry policy at lines 125–127 (transient 5xx/429) and 100–102 (network failures). PUT and DELETE are already idempotent at the HTTP level; injecting Idempotency-Key is fine. BUT: the same Idempotency-Key is used across retries (good — that's the point) AND the retry policy is shared with non-idempotent POST. The combined behavior is correct only IF the remote service honours Idempotency-Key. There is no opt-out for callers who know the remote service does NOT support it, in which case a retried POST could double-create. The docblock claims "the remote service can dedupe our retries" but offers no safety net when it cannot. Either: (a) require explicit caller opt-in for POST retries, or (b) document this assumption loudly. Currently silent.
- **HIGH** — OutboundHttpClient.php:125 — Retry decision uses only `in_array($response->statusCode, $this->retryStatuses, true)` without respecting `Retry-After` header on 429/503. Backoff blindly follows `$backoffSeconds` even when the server explicitly told us when to come back. Real services will keep returning 429 if you don't honour their interval.
- **MEDIUM** — OutboundHttpClient.php:185–195 — `sleepBackoff` clamps index to `count($backoffSeconds) - 1`. If `maxAttempts > count($backoffSeconds)` (e.g. maxAttempts=5, default backoff=[1,3,10]), all attempts ≥3 sleep 10s. No exponential growth past the array. Edge case behaviour is conservative but is presented as "exponential" in the docblock at line 16 — misleading naming.
- **MEDIUM** — OutboundHttpClient.php:194 — `usleep($seconds * 1_000_000)` blocks the entire PHP-FPM worker. For maxAttempts=3 with default backoff, worst-case a request can hang the worker for 1+3 = 4 seconds before throwing. No jitter — N concurrent retries to the same failing dependency will all wake at the same time (thundering herd).
- **LOW** — OutboundHttpClient.php:155 — `bin2hex(random_bytes(16))` is CSPRNG; correct choice. 32 hex chars = 128 bits of entropy.
- **MEDIUM** — OutboundHttpClient.php:104–109 — On exhausted-retries-from-network-failure, the HttpException carries `lastResponse: null` even though earlier responses in the loop may have populated `$lastResponse`. The state machine intermingles "transport failure path" with "status-retry path" but only the status path sets `$lastResponse`. If attempt 1 returns 503 (sets $lastResponse) and attempt 2 throws a network error, the catch block throws WITHOUT carrying the 503. Minor diagnostic loss.
- **LOW** — OutboundHttpClient.php:147–149 — Auto-correlation injects `X-Correlation-Id` from `CorrelationIdService::get()`. In a CLI/worker context, this is the first call → lazy UUID generation per worker boot, which means every outbound call in a long-running worker shares the same correlation id (see CorrelationIdService finding below).

### IdempotencyMiddleware

- **HIGH** — IdempotencyMiddleware.php:152 — `actorId()` instantiates `new ActorResolver()` directly. If the actor cannot be resolved (unauthenticated, or resolver throws), the entire idempotency lookup fails before reaching the route. Unauthenticated requests with an Idempotency-Key would error out unpredictably. Verify ActorResolver returns a sentinel actor (e.g. id=0) for guests; if it throws, this middleware breaks public endpoints that opt-in.
- **HIGH** — IdempotencyMiddleware.php:122–124 — `replay()` only restores `Content-Type` (line 123). Other crucial headers (Location for 201, Cache-Control, ETag, custom domain headers) are dropped. Replay is NOT semantically identical to the original response. Either store all headers in `response_headers` JSON or document the limitation clearly.
- **MEDIUM** — IdempotencyMiddleware.php:111–127 — Cache write happens AFTER the response is finalized. If the server crashes or the write fails (logged at line 130), the client retry will hit the live handler again — double execution. RFC 7240 / Stripe-style idempotency requires writing BEFORE executing OR locking on the key. The current "best-effort post-hoc" approach silently breaks idempotency guarantees on transient cache failures.
- **MEDIUM** — IdempotencyMiddleware.php:106–108 — Re-lookup just before insert is a TOCTOU race: two concurrent retries with the same key both pass `before()` (no row yet), both execute the handler, both reach `after()`. The unique index will reject one INSERT (caught at line 128, logged as warning), but the handler ALREADY ran twice. Real protection requires `INSERT ... ON DUPLICATE KEY` with a "pending" status set in `before()`, then update in `after()`.
- **MEDIUM** — IdempotencyMiddleware.php:155–163 — Request hash hashes only method+path+body, NOT headers (e.g. Authorization, Accept-Language). Two different authenticated users with the same key would collide if the key scoping by actor_id (line 67) weren't there — and it is, so OK. But Accept variations (JSON vs XML) would replay the wrong content type.
- **LOW** — IdempotencyMiddleware.php:111–127 — `expires_at` is computed at write time but `lookup()` filters on `expires_at > now`. TTL enforcement is correct. Cleanup of expired rows is not handled here; presumably a cron — verify.
- **LOW** — IdempotencyMiddleware.php:147 — Key regex `[A-Za-z0-9._-]+` is fine but the spec at RFC-draft Idempotency-Key allows quoted strings with broader chars. Tightening is OK for safety.

### CsvReader

- **PASS** — CsvReader.php:70–92 — Yields one row at a time via generator. `fromFile` uses `fopen` directly (streaming). `fromString` writes whole string into `php://temp` (line 51) — this DOES buffer the entire input in memory before iteration, contradicting "200k-row spreadsheet doesn't OOM" in the docblock. Worth noting: `fromString` is bounded by string size.
- **MEDIUM** — CsvReader.php:45–55 — `fromString` buffers everything via fwrite + rewind. For a 200MB CSV passed as string, the whole thing sits in `php://temp` (which spills to disk at 2MB by default, so not OOM, but copies the input). Document or recommend `fromFile` for large inputs.
- **MEDIUM** — CsvReader.php:118–121 — BOM stripped only from the first cell of the HEADER ROW (because `$lineNumber` is 1 at header time). Subsequent rows also get the regex applied to their first cell, harmlessly (no BOM to strip). Wait — re-reading: `readRow()` is called for EVERY row including data rows, so BOM stripping runs on every row's first cell. Wasted regex on every row, but correct. Performance, not correctness.
- **HIGH** — CsvReader.php:80–87 — Header mismatch detection is row-count comparison only. A row with the right number of columns but mis-ordered values passes through silently as `array_combine(['email','name'], ['bob','bob@x'])` — header strict, semantics not. CSV cannot detect this; documented behaviour, but the docblock claims "header-strict" which oversells. Clarify: only column COUNT is strict.
- **LOW** — CsvReader.php:108 — Pass-through of `$escape = '\\'` — PHP 8.4 default. fgetcsv signature is fine.
- **MEDIUM** — CsvReader.php:46 — `fopen('php://temp', 'r+')` has no error suppression, but `fopen` on `php://temp` essentially never fails. The check is defensive but cannot be exercised in tests easily.

### CsvWriter

- **HIGH** — CsvWriter.php:55–62 — `toString` writes to `php://temp`, then `contents()` (line 89) calls `stream_get_contents` → loads the entire file into a string. NOT streaming. For a 200k-row export this materialises the whole CSV in memory. Docblock at line 8 ("Memory-friendly") is misleading; only `toFile` actually streams to disk.
- **HIGH** — CsvWriter.php — No UTF-8 BOM emission. CsvReader strips BOM (line 118), but CsvWriter never writes one. Excel on Windows opens BOM-less UTF-8 CSV as Latin-1, mangling non-ASCII (R$, é, ç). Add an option `withBom: bool = false` and prepend `"\xEF\xBB\xBF"` once before the header.
- **MEDIUM** — CsvWriter.php:91–94 — `contents()` rewinds and reads the entire stream. Calling it twice is fine but each call rewinds and re-reads. If `writeRow` is called between two `contents()` calls, the second read includes the new row but the file pointer is at EOF after `contents()`. Subtle stateful behaviour, but not broken.
- **LOW** — CsvWriter.php:110–112 — bool → "1"/"0" coercion is opinionated. Floats keep native PHP formatting (e.g. `0.1 + 0.2` → `0.30000000000000004`). DateTime → fatal error (no __toString). Stringify is `scalar|null` per type hint, so DateTime is the caller's problem, but worth a docblock warning.

### BulkImportRunner

- **MEDIUM** — BulkImportRunner.php:33–43 — Iterator type-coercion via wrapping closure adds a useless layer when reader already returns a Generator. The `&` in `&$iterator` (line 90) suggests rewind/peek, but generators can't rewind, and the validation code at lines 105–106 uses `$iterator->current()` WITHOUT advancing — so the first row gets re-consumed in the foreach at line 49. Wait: actually `$iterator->valid()` + `current()` does NOT advance. The foreach at line 49 will then `rewind` (allowed on un-iterated generator only) or start from current. PHP `foreach` on a Generator after only calling `current()`/`valid()` works: it starts from the current position. So first row IS processed. OK, but the by-reference `&$iterator` is misleading — no reassignment happens.
- **HIGH** — BulkImportRunner.php:105–106 — Header validation peeks via `$iterator->current()` which returns the FIRST DATA ROW (because CsvReader's `rows()` yields keyed-by-header arrays starting at line 2). The "header" used for validation is `array_keys($firstRow)` = the header strings (from array_combine). Correct, but conceptually confusing — the runner trusts the reader to surface header as the keys of the first yielded row. If the CSV is header-only (just one line), `valid()` is false and the validation considers requiredColumns required → throws. But a single-row CSV (header + 0 data rows) means the runner's required-column check NEVER runs because `valid()` is false on an empty data set. Wait re-reading CsvReader: line 72 reads header, line 78 loop only yields data rows. So a CSV with header only → iterator is empty → `valid()=false` at line 92 → branch treats it as empty file and runs the "missing columns" check using `requiredColumns()` (line 94). That means a header-only CSV ALWAYS throws if any columns are required, even if the header is valid. Wrong: an empty-but-valid import should report 0 rows processed, not throw.
- **MEDIUM** — BulkImportRunner.php:49–67 — Per-row error captures `$lineNumber` from the iterator key (CsvReader yields with line number as key — good). Verified at CsvReader.php:90.
- **PASS** — BulkImportRunner.php:53–54 — `dryRun` correctly skips `process()` but still calls `mapRow()` (validation-only mode). Documented.
- **LOW** — BulkImportRunner.php:78 — Returns ImportSummary even when dryRun. `dryRun` flag propagated. Good.

### ImportSummary

- Clean. No findings.

### LoggerFactory

- **PASS** — LoggerFactory.php:50–62 — Processor stack order is documented (line 59–62) and CORRECT: RedactingProcessor pushed LAST → runs FIRST in LIFO. So redaction happens before any other processor inspects context. Good defensive design, comment is accurate.
- **MEDIUM** — LoggerFactory.php:121–155 — CQRS context processor is pushed BEFORE RedactingProcessor (LIFO: redaction runs first → then correlation id → then CQRS context). This means redaction runs on the ORIGINAL context only; if the CQRS processor injected a sensitive value (it doesn't, but if extended), it would NOT be redacted. Defensive ordering. OK.
- **LOW** — LoggerFactory.php:36–37 — `LOG_FILENAME = 'app.json'` constant. RotatingFileHandler will produce `app-YYYY-MM-DD.json`. No issue.
- **MEDIUM** — LoggerFactory.php:75–82 — Fallback path `__DIR__ . '/../../../writable/logs/'` is fragile (4 levels up). If the class moves, silently breaks. Use a single source of truth (env var `LOG_PATH` or a constant in bootstrap).
- **LOW** — LoggerFactory.php — Channel naming convention is mentioned in docblocks (lines 28–32, 113–117) but not enforced. A future caller can pass `"my random string"` and CQRS context processor short-circuits at line 132. Not a bug — graceful degradation.
- **MEDIUM** — LoggerFactory.php:46 — No log handler for errors that occur during logger CONSTRUCTION (e.g. logs directory unwritable). `RotatingFileHandler` will throw on the first write attempt, not at factory time. Logger creation succeeds, first log call throws → cascading failure in handlers that catch nothing.

### LoggingServiceProvider

- **LOW** — LoggingServiceProvider.php — Pure pass-through wrapper. `createLogger` and `createDomainLogger` could be on LoggerFactory directly. Slim adapter is the documented framework-boundary, so keep.

### CorrelationIdService

- **CRITICAL** — CorrelationIdService.php:33,60–66 — Static `$correlationId` persists for the entire PHP process. In a long-running worker (Roadrunner, Swoole, Octane-style, queue worker consuming N messages without restart), the FIRST request/message generates a UUID, then EVERY subsequent request/message sees the same id. All logs across all jobs in that worker share one correlation id → trace correlation is destroyed.
  - CorrelationIdMiddleware::before (line 41) overwrites on inbound header, so HTTP requests WITH valid X-Correlation-Id are safe. HTTP requests WITHOUT the header reuse the previous request's id.
  - Queue/Console: no middleware → all jobs share one id forever.
  - Tests must call `::clear()` (they do, confirmed in 8 test files), but production has NO call site for `::clear()` outside tests. Verified: grep shows zero production callers of `clear()`. Add a "clear on request end" hook in CorrelationIdMiddleware::after, or reset before each command/event dispatch.
- **HIGH** — CorrelationIdService.php:43–50 — Fallback UUID code path is sound (CSPRNG, RFC 4122 variant + version bits), but `ramsey/uuid` is presumably a hard dep — check composer.json. If it's a hard dep, the fallback is dead code; if it's optional, the check is right.
- **LOW** — CorrelationIdService.php — No locking around the static. PHP shared-nothing means single-thread per request → OK for FPM. Breaks under threaded SAPIs (Swoole coroutines share globals). Document the assumption.

### CorrelationIdMiddleware

- **PASS** — CorrelationIdMiddleware.php:36–50 — Inbound validation strict (length 8..128, regex `[A-Za-z0-9._-]+`). Invalid headers silently ignored — server falls back to its own generation. Correct trust posture.
- **PASS** — CorrelationIdMiddleware.php:55–60 — Outbound `X-Correlation-Id` header echoed on response.
- **MEDIUM** — CorrelationIdMiddleware.php:55–60 — `after()` does NOT call `CorrelationIdService::clear()`. In a long-running worker that reuses the process between requests, the next request without a header gets the previous request's id. See CorrelationIdService finding above. Add `CorrelationIdService::clear()` at the END of `after()`, AFTER setting the header.
- **LOW** — CorrelationIdMiddleware.php:69 — Same regex as IdempotencyMiddleware; consider extracting a shared validator.

### RedactingProcessor

- **PASS** — RedactingProcessor.php:31–50 — SENSITIVE list covers: password (+ variants), token (+ jwt, access, refresh), authorization, api_key, secret, private_key, credit_card, card_number, cvv. Substring match catches `auth_token`, `Authorization`, `X-Api-Key`, `creditCard` (lower-cased before compare).
- **PASS** — RedactingProcessor.php:64–83 — Recursive descent over nested arrays. Case-insensitive (line 87).
- **MEDIUM** — RedactingProcessor.php:31–50 — Does NOT cover: `pwd`, `pass`, `auth` (alone, no underscore), `session_id`, `bearer`, `cookie`, `set_cookie`, `client_secret`, `private`, `pin`, `ssn`, `iban`, `account_number`. The substring strategy means `pass` would also match `passenger` (false positive) — defensive but may break legitimate fields. Trade-off is OK.
- **MEDIUM** — RedactingProcessor.php:74–77 — Recurses into arrays but does NOT inspect array VALUES that are sensitive strings (e.g. `['header_line' => 'Authorization: Bearer xyz']`). Only key-based redaction. If a JWT lands in a value under a non-sensitive key, it leaks. Consider value-pattern matching for JWTs (`/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/`).
- **LOW** — RedactingProcessor.php:85–96 — `int|string $key` accepted, but the data type is `array<string, mixed>` per docblock — int keys would only show up if list arrays leak in. Defensive.
- **MEDIUM** — RedactingProcessor.php — Does NOT redact `$record->message`. Loggers commonly write `"User login failed for password=foo"` via interpolation; the message string is untouched. Add scan of message via the same SENSITIVE list (pattern: `key=value` or `"key":"value"`).
- **MEDIUM** — RedactingProcessor.php — Objects (e.g. `Throwable`, DTOs) are passed through untouched (line 79). A `\Throwable` carries `getMessage()` that may include sensitive data when stringified by the formatter; never inspected. Stringify-then-scan or document.

### DomainLogger

- **LOW** — DomainLogger.php:34–89 — STILL ACTIVELY USED by User domain (AccessToken, Email, PasswordComplexity, UserName, User entity — 11 call sites). NOT superseded by LoggerFactory; it's a static convenience wrapper AROUND LoggerFactory for value objects/entities that can't take constructor injection. The Cookie domain template (per CLAUDE.md) prefers constructor injection. So coexistence is intentional: VOs use DomainLogger, handlers use injected PSR-3.
- **MEDIUM** — DomainLogger.php:43–50 — Singleton logger keyed only to channel `'domain.validation'`. ALL domains share the same channel — the CQRS context processor (LoggerFactory:121) will set domain="domain" (parsed from "domain.validation"), losing the actual domain name. The actual domain is in the context payload (line 62), but the CHANNEL is wrong. Fix: parameterize the channel by domain, e.g. `LoggerFactory::create("{$domain}.validation")`.
- **LOW** — DomainLogger.php:85–88 — `reset()` for testing; no production caller. Same risk as CorrelationIdService — long-lived workers retain a stale logger across requests. Less impactful since the logger is stateless aside from handlers.
- **MEDIUM** — DomainLogger.php — Inconsistent API surface vs LoggerFactory/PSR-3. New domain code per CLAUDE.md should inject `LoggerInterface`, but the User domain uses this static. Either deprecate DomainLogger or document when each is appropriate.

## Verdict

**HIGH-CRITICAL action items:**
1. **CRITICAL** — CorrelationIdService static state persists across requests in long-running workers. Add `clear()` in `CorrelationIdMiddleware::after` and at boundaries of queue/command dispatching.
2. **CRITICAL** — OutboundHttpClient auto-retries non-idempotent POSTs assuming the remote service honours Idempotency-Key; no opt-out or guard. Either gate POST retries behind explicit opt-in or document loudly.
3. **HIGH** — IdempotencyMiddleware writes the cache row AFTER handler executes; two concurrent retries can both execute. Needs upfront row write (status pending) or per-key lock.
4. **HIGH** — IdempotencyMiddleware replay only restores Content-Type; Location/ETag/custom headers dropped. Restored responses are not semantically identical.
5. **HIGH** — CurlHttpTransport allows arbitrary URL schemes; SSRF surface. Lock to HTTP/HTTPS via `CURLOPT_PROTOCOLS`.
6. **HIGH** — OutboundHttpClient does not respect `Retry-After` on 429/503.
7. **HIGH** — CsvWriter::toString is NOT streaming despite "memory-friendly" docblock; loads full file into memory on `contents()`.
8. **HIGH** — CsvWriter never emits BOM; Excel on Windows will mis-decode UTF-8 exports.
9. **HIGH** — BulkImportRunner throws on header-only CSV when required columns exist, even if header is valid; empty-but-valid imports should return zero-row summary, not throw.
10. **HIGH** — IdempotencyMiddleware::actorId throws if ActorResolver fails; needs guard for unauthenticated routes.

**MEDIUM cluster:** missing redaction of log messages and value-pattern JWT leaks; DomainLogger uses single shared channel losing domain context; OutboundHttpClient `usleep` blocks worker, no jitter; idempotency request-hash ignores headers.

**PASS:** RedactingProcessor LIFO ordering, SSL verification, ApiResponse problem+json content-type ordering, CSP-RN G in Idempotency-Key generation, CorrelationIdMiddleware inbound validation strictness.

**Module health:**
- HTTP envelope (ApiResponse): solid, minor consistency nits.
- HTTP client + Idempotency: functional but assumes a lot about remote-service behaviour; needs hardening for production traffic.
- Bulk CSV: works for happy path, weak on edge cases (empty CSV, BOM, large exports).
- Logging stack: well-designed processor ordering, but correlation-id worker-leakage and DomainLogger channel bug are real risks.
