# 12 — Storage / Notifications / Settings / I18n / Email

## Files audited

- app/Infrastructure/Storage/StorageInterface.php
- app/Infrastructure/Storage/LocalStorage.php
- app/Infrastructure/Storage/StorageException.php
- app/Infrastructure/Storage/AttachmentService.php
- app/Infrastructure/Notifications/Notification.php
- app/Infrastructure/Notifications/NotificationLevel.php
- app/Infrastructure/Notifications/NotificationService.php
- app/Infrastructure/Settings/SettingsService.php
- app/Infrastructure/I18n/LocaleResolver.php
- app/Infrastructure/I18n/LocaleMiddleware.php
- app/Infrastructure/Email/EmailService.php
- app/Views/emails/layout.php
- app/Views/emails/auth/password_reset.php

## Findings

### Storage

- **HIGH** — `LocalStorage::resolveKey` LocalStorage.php:108-112. Traversal guard bypassed on first `put` to a not-yet-existing nested dir: `realpath(dirname($path))` returns `false` for non-existent parents → code falls back to `$parent = $baseDir`, so `str_starts_with($parent,$baseDir)` is trivially true regardless of `$key`. The substring checks on line 100 (`..`, leading `/`, NUL) are the only real defence; anything that evades them (e.g. backslashes on Linux are not separators but on edge cases via odd FS, or symlinked subdirs created by another process) gets through unchecked. Resolve `realpath` after `mkdir`, or normalise `$path` with explicit `..`/`.` segment removal before checking.
- **HIGH** — `LocalStorage::resolveKey` LocalStorage.php:100. `str_contains($key, '..')` rejects legitimate filenames like `report..final.pdf`. Should split on `DIRECTORY_SEPARATOR`/`/` and reject any segment equal to `..`, not substring match.
- **MEDIUM** — `LocalStorage::put` LocalStorage.php:47-50. Non-atomic write. `file_put_contents` writes in place; a crash/oom mid-write leaves a partial file under a key callers will then read as truncated. Standard fix: write to `${path}.tmp.${pid}` then `rename()`.
- **MEDIUM** — `LocalStorage::put`/`delete` LocalStorage.php:39-83. No locking. Concurrent `put` + `delete` on the same key (e.g. retried upload racing a tenant purge) is fully unsynchronised; `delete` may race ahead of `put`, leaving the row pointing at a missing file. No `flock`, no rename-based atomicity.
- **MEDIUM** — `LocalStorage::delete` LocalStorage.php:82. `@unlink($path)` swallows every failure (perm denied, EBUSY, EIO). Caller has no idea the bytes are still on disk. Should throw `StorageException` on `unlink === false`.
- **LOW** — `LocalStorage::exists` LocalStorage.php:66-74. Catching `StorageException` and returning `false` for invalid keys hides malformed input from callers. Reads-of-junk-keys silently look like "not yet uploaded".
- **LOW** — Storage stack ships only `LocalStorage`; no S3/abstract driver placeholder in the namespace beyond `StorageInterface`. `storage_driver` column is recorded (AttachmentService.php:73) but never consulted on read (AttachmentService.php:108 always uses `$this->storage`). Cross-driver migrations and multi-driver tenants will break.
- **LOW** — `StorageInterface::put` doc says overwrites are idempotent (StorageInterface.php:23-27); contract gives no way to assert "create-only" / write-once, so accidental key collision silently clobbers prior uploads.

### Attachments

- **HIGH** — `AttachmentService::attachTo` AttachmentService.php:46-60. `attachableType` is an unvalidated free-form string. There is no `class_exists($attachableType)` / no allow-list. The row gets persisted with whatever the caller passed, and any downstream code that does `new $row['attachable_type']` or `$repo = match($type)` becomes a polymorphic-deserialisation foothold. Must validate against an enumerated registry of attachable aggregates.
- **HIGH** — `AttachmentService::read` AttachmentService.php:102-109 and `::delete` AttachmentService.php:115-131 do not filter by `tenant_id`. Any caller with an integer `$id` can fetch / soft-delete any tenant's row. Controller-level checks are not sufficient when these methods are reachable from queues, CLI commands, etc. Tenant filtering belongs in the service.
- **HIGH** — `AttachmentService::attachTo` AttachmentService.php:62-83. `storage->put` runs before the DB insert and is not in a transaction with it. If the insert throws (FK failure, unique violation, deadlock), the bytes are already on disk → orphan file, no row. Either insert first then put, or wrap put in a try/catch that calls `storage->delete` on insert failure.
- **MEDIUM** — `AttachmentService::listFor` AttachmentService.php:136-154 ignores `tenant_id`. Same cross-tenant leak as `read`/`delete`.
- **MEDIUM** — `AttachmentService::delete` AttachmentService.php:115-131. Doc says "soft-delete (sets deleted_at + deletes underlying bytes)" — but a "soft delete" that destroys the file makes the row useless for any future re-issue / re-download / audit-trail-with-replay. Either soft-delete preserves bytes, or hard-delete should remove the row too. Current behaviour is the worst of both worlds: audit row exists, but `read()` will now throw `StorageException` from storage.
- **MEDIUM** — `AttachmentService::buildKey` AttachmentService.php:173-187. `$idSlug` derived from raw `$attachableId` via regex — fine, but multiple distinct ids collapsing to the same slug (e.g. `42 ` and `42_`) is possible. UUID adds entropy so collisions don't lose data, but the directory layout becomes ambiguous for ops.
- **LOW** — Key entropy AttachmentService.php:184: `bin2hex(random_bytes(8))` = 64 bits. With a deterministic public prefix (`{type}/{id}/`) an attacker who knows the owner needs only to guess 64 bits — sufficient, but UUIDv4 (122 bits) is the standard answer and costs nothing.
- **LOW** — `AttachmentRef::checksumSha256` is computed (AttachmentService.php:66) but never re-verified on `read`. A silent disk-bitflip / external mutation goes undetected.
- **LOW** — `attachTo` AttachmentService.php:75. `mime_type` defaults to `application/octet-stream` if empty, but otherwise trusts whatever client sent. No server-side sniff (`finfo_buffer`). Means stored MIME may misrepresent the bytes.

### Notifications

- **HIGH** — Tenant scoping is missing from `listFor` NotificationService.php:86-107, `countUnread` :109-116, `markRead` :121-131, and `markAllRead` :133-142. `notify` accepts and stores `tenant_id` (:55, :68) but reads never filter on it. A user with access to two tenants sees a merged inbox; cross-tenant data leak in any UI that doesn't filter again on top.
- **MEDIUM** — `type` is a free-form string (NotificationService.php:47-49, Notification.php:18). NotificationLevel is an enum; `type` should be too (or a registered constant set). Today the code happily writes `inovice.appoved` and nobody notices.
- **MEDIUM** — `listFor` has `limit` but no `offset` / no cursor (NotificationService.php:86-107). For an active user this means "page 2" is unimplementable; the UI is forced to either show the first 50 only or pull everything.
- **MEDIUM** — `markRead` NotificationService.php:121-131. Return value is `affectedRows() === 1`, which conflates "not yours" with "already read" — both return `false`. Caller cannot distinguish 403 from 200-noop. Either drop the `read_at IS NULL` filter and check ownership separately, or document the conflation.
- **LOW** — `notify` NotificationService.php:66-78. No transactional outbox / no event emission — fully synchronous DB insert. A delivery failure here will fail the surrounding command; consider routing through the existing `Outbox` for at-least-once.
- **LOW** — `hydrate` NotificationService.php:168 calls `NotificationLevel::from(...)`. If a legacy/external writer puts an unknown level in the table, `from` throws `ValueError` → entire list query 500s instead of degrading to `Info`. Use `tryFrom` with a fallback.
- **LOW** — `data_json` decoded with `json_decode($json, true)` (NotificationService.php:153) without `JSON_THROW_ON_ERROR`. Malformed JSON silently becomes `[]`; encode uses throw mode, decode doesn't.

### Settings

- **MEDIUM** — `SettingsService::decodeValue` SettingsService.php:161-168. Swallows `JsonException` and returns the raw JSON string as the value. A row whose encoding was corrupted silently round-trips back as the literal `"{...}"` string. Type-strict callers then explode far away from the cause. Should log + throw, or at least surface to a known sentinel.
- **MEDIUM** — `SettingsService::set` SettingsService.php:66-96. Upsert is not transactional and there's no application-level lock; two concurrent first-time `set` calls for the same `(key, tenant)` both miss `existingRowId` and both `insert` → duplicate rows. Requires a UNIQUE `(key_name, tenant_id)` index plus `ON DUPLICATE KEY UPDATE` (or wrap in a transaction + `SELECT ... FOR UPDATE`).
- **MEDIUM** — `SettingsService::encodeValue` SettingsService.php:170-173. `json_encode(JSON_THROW_ON_ERROR)` cannot serialise binary strings or values with invalid UTF-8 — caller passing a raw file blob or `chr(0xff)` will throw `JsonException` and the `set` call propagates an uncaught exception. Either reject non-scalar/non-array values, or base64-wrap binary, but document it.
- **LOW** — Tenant fallback policy SettingsService.php:21-26. Documentation says "callers should explicitly fall back to the global one", but nothing in the API encodes this. A convenience `getWithFallback(string $key, ...$tenantId)` would prevent the obvious "engineer forgets fallback at one of N call sites" bug.
- **LOW** — `existingRowId` SettingsService.php:151-159 calls `fetchRow` and `set` calls it again at :84 → two identical SELECTs per write. Minor, but on hot keys it doubles read load.
- **LOW** — `forget` SettingsService.php:101-110 silently no-ops when nothing was deleted (no audit). Useful for idempotency, but logging would help diagnose "why didn't my flag toggle".
- **LOW** — Cache is per-instance, not per-request (SettingsService.php:32). If the container hands out a new instance later in the request, the cache is empty. Memoise on a static, or require the service to be a true singleton in the DI config.

### I18n

- **MEDIUM** — `LocaleResolver::fromAcceptLanguage` LocaleResolver.php:91-118. `q=0` is treated like any other quality, but RFC 7231 says `q=0` means "explicit reject — never serve". A header `de, en;q=0` will pick `en` even though the client said no. Skip entries with `quality <= 0`.
- **MEDIUM** — `LocaleResolver::fromAcceptLanguage` LocaleResolver.php:104-106. Q-parse only looks at `$tokens[1]`. A header with extra params like `en;foo=bar;q=0.9` puts `q=0.9` in `$tokens[2]` and is silently ignored → defaults to `1.0`. Iterate all tokens.
- **LOW** — `LocaleResolver::resolve` LocaleResolver.php:41-57. Resolution order has session FIRST. Means `?locale=xx` only takes effect because `LocaleMiddleware::before` calls `persistChoice` (LocaleMiddleware.php:43-45) which writes the validated locale into the session before the next `resolve` call. Functional today, but two-step coupling between resolver and middleware is fragile; if any caller invokes `resolve` directly the query param will be silently ignored on the first request.
- **LOW** — Session fixation surface LocaleMiddleware.php:43-46. Persisting the (validated) locale on `?locale=xx` does not regenerate the session id. The value itself is whitelisted so there's no XSS / data-injection vector, but if other code reads `session()->get('locale')` raw without lowercasing/validating, a previously-persisted value can survive across login boundaries.
- **LOW** — `LocaleResolver::matchSupported` LocaleResolver.php:140-157 only tries full tag → primary subtag. A request for `zh-Hant` won't fall back to `zh-tw` (or vice versa); region/script-only matches are missed.
- **LOW** — `LocaleMiddleware::after` LocaleMiddleware.php:62-69. Appends `Accept-Language` to `Vary` unconditionally without dedup — if some other filter already added it, the header becomes `Vary: Accept-Language, Accept-Language`. Harmless but ugly.
- **LOW** — `LocaleResolver::userPreferredLocale` LocaleResolver.php:127-130 hard-coded to `null` with a phpstan ignore. Dead branch; either implement against the users table or remove the candidate slot.

### Email

- **MEDIUM** — `EmailService::sendTemplate` EmailService.php:44-64. `$view` is passed straight to CI4's `view()` helper with no allow-list and no normalisation. CI4 will load any `.php` under `app/Views/` matching the dotted path — if a caller ever forwards user input (e.g. a webhook with a "template" field) the result is **arbitrary local file inclusion within `app/Views/`** and unintended template rendering. Lock to an explicit map of known templates (e.g. an enum `EmailTemplate::PasswordReset → 'emails/auth/password_reset'`).
- **MEDIUM** — `EmailService::dispatchViaCodeIgniter` EmailService.php:149. `$email->printDebugger(['headers'])` is logged on failure. SMTP headers contain `From:`/`Reply-To:`/`To:`, and the underlying CI4 debugger has historically included the SMTPUser. Will leak addresses (PII) and may leak the SMTP login. Redact, or log only error code + `printDebugger([])` without headers.
- **MEDIUM** — `EmailService::sendEmail` / `dispatchViaCodeIgniter` EmailService.php:124-152. `$to` and `$subject` flow into `setTo`/`setSubject` with no `\r\n` stripping. CI4's Email class does sanitise headers internally in current versions, but defence-in-depth is cheap: reject any subject/to containing `\r` or `\n` at this layer.
- **LOW** — No plain-text alternative is emitted (EmailService.php:131-132 only `setMessage($html)`). Spam filters down-rank HTML-only mail; recipients with text-mode clients see nothing useful. Generate a stripped text part.
- **LOW** — `EmailService::sendPasswordResetEmail` EmailService.php:71-84. `expiresInMinutes` is hard-coded to `60` and never compared against the actual token TTL — drift between auth config and the email body is silent.
- **LOW** — `emails/layout.php` layout.php:17-19 defaults `$supportEmail` to `'noreply@localhost'`. Footer then renders a `mailto:noreply@localhost` link — gives end users a non-functional contact when SettingsService hasn't been wired. Default to the configured FROM, or omit the link block when unset.
- **LOW** — `emails/layout.php` layout.php:22 has `<html lang="en">` hard-coded; even when the rest of the app honours `LocaleResolver`, the email body advertises `en`.
- **LOW** — `EmailService` EmailService.php:53. `view($view, $payload)` failures are caught and return `false`, but the caller (e.g. `sendPasswordResetEmail`) has no way to distinguish "transport failed" from "template missing" — both look like a boolean false. Surface a more specific result or throw a typed `EmailException` upstream.
- **LOW** — Inline-HTML smell is *mostly* gone from `EmailService`, but the layout still inlines all styles (acceptable for email clients) — flagging only that "no inline HTML" is not literally achievable for transactional mail; the doc-comment at EmailService.php:14-18 overstates.

## Verdict

**Storage:** path-traversal guard is incomplete — the realpath-of-nonexistent-parent fallback (LocalStorage.php:108-110) is the real bug; substring `..` check is naive. Writes are non-atomic and unsynchronised. Driver layer exists but is single-driver in practice. **Not production-safe yet.**

**Attachments:** three HIGHs — unvalidated polymorphic `attachable_type`, missing tenant scoping on read/delete/list, and orphan-on-insert-failure. Storage-key entropy is acceptable; checksums computed but never verified. **Block release until tenant-scoping and type-allow-list land.**

**Notifications:** ownership check on `markRead` is correctly present, but tenant scoping is absent everywhere except `notify`'s parameter list. Free-form `type` and missing pagination are functional gaps. **HIGH on multi-tenant safety.**

**Settings:** upsert race + JSON-encode-binary failure mode are the realistic foot-guns. Per-request cache is correctly invalidated. Tenant fallback policy is documentation-only — needs a real API. **MEDIUM overall.**

**I18n:** matching handles the common cases (`pt-BR→pt-br`, `en-US→en`). Two RFC-7231 misses: `q=0` not honoured, and `q=` not searched past `$tokens[1]`. Session fixation surface is minimal because values are whitelisted. **LOW/MEDIUM.**

**Email:** the loud finding is `sendTemplate`'s unvalidated `$view` — LFI-within-Views if any caller ever forwards untrusted input. Header logging may leak SMTP creds. No text alternative. **MEDIUM — fix the view allow-list before any caller starts taking template names from request input.**
