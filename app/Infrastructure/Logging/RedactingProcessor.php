<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\LogRecord;

/**
 * Redacts sensitive fields from log records before they are written.
 *
 * Many handlers and middlewares legitimately pass through request payloads
 * for diagnostic purposes. Without redaction those payloads can include
 * plaintext passwords, JWT tokens, refresh tokens, API keys, and other
 * credentials. This processor walks the context and extra trees recursively
 * and replaces any value whose key matches a sensitive name with `***`.
 *
 * The match is case-insensitive and substring-based ("auth_token" and
 * "Authorization" both match). New keys can be appended to {@see SENSITIVE}.
 *
 * Push it LAST on the processor stack so other processors (correlation id,
 * cqrs context) cannot accidentally re-introduce sensitive data after redaction.
 */
final class RedactingProcessor
{
    public const string MASK = '***';

    /**
     * Canonical list of sensitive key substrings.
     *
     * Public so other components that have to redact (e.g.
     * {@see \App\Infrastructure\Bus\Middleware\AuditMiddleware}) can share
     * the same list — drift between these lists is itself a security
     * incident waiting to happen.
     *
     * @var list<string>
     */
    public const array SENSITIVE = [
        'password',
        'password_hash',
        'password_confirm',
        'new_password',
        'old_password',
        'current_password',
        'plaintext',
        'token',
        'refresh_token',
        'access_token',
        'jwt',
        'authorization',
        'api_key',
        'secret',
        'private_key',
        'credit_card',
        'card_number',
        'cvv',
    ];

    /**
     * isSensitive.
     *
     * @param int|string $key
     * @return bool
     */
    public static function isSensitive(int|string $key): bool
    {
        $needle = strtolower((string) $key);
        foreach (self::SENSITIVE as $marker) {
            if (str_contains($needle, $marker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * __invoke.
     *
     * @param LogRecord $record
     * @return LogRecord
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra)
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function redactArray(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (self::isSensitive($key)) {
                $out[$key] = self::MASK;
                continue;
            }

            if (is_array($value)) {
                $out[$key] = $this->redactArray($value);
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
