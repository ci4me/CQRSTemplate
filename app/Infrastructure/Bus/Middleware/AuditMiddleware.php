<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus\Middleware;

use App\Infrastructure\Auth\Services\ActorResolver;
use App\Infrastructure\Bus\CommandMiddlewareInterface;
use App\Infrastructure\Logging\CorrelationIdService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Psr\Log\LoggerInterface;

/**
 * Writes one row per command to the `audit_log` table.
 *
 * Pipeline placement:
 * MUST be inside {@see TransactionMiddleware} so the audit row commits
 * with the business write (and rolls back together on failure of a
 * downstream middleware). Pipeline order:
 *   LoggingMiddleware -> TransactionMiddleware -> AuditMiddleware -> handler
 *
 * What is stored:
 * - The command's FQCN
 * - The current actor (via {@see ActorResolver})
 * - Correlation id
 * - Success/failure status and duration
 * - A SHA-256 digest of a JSON-encoded REDACTED payload. We store a digest,
 *   not the payload, so password/token-bearing commands stay safe even if
 *   the audit table leaks. The digest is reproducible from the redacted
 *   payload, which is enough for tamper detection and duplicate detection.
 *
 * Failure handling:
 * If the audit insert itself fails we DO NOT abort the command — the audit
 * is a side-channel, not a business invariant. We log the failure at error
 * level so monitoring catches it.
 */
final readonly class AuditMiddleware implements CommandMiddlewareInterface
{
    /**
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     */
    public function __construct(
        private LoggerInterface $logger,
        private ActorResolver $actorResolver,
        private ?BaseConnection $db = null
    ) {
    }

    public function handle(object $command, callable $next): mixed
    {
        $startMs = microtime(true) * 1000;
        $actor = $this->actorResolver->resolve(null);
        $digest = $this->digestOf($command);

        try {
            $result = $next($command);
        } catch (\Throwable $e) {
            $this->writeRow(
                command: $command,
                actorId: $actor->id,
                status: 'failure',
                digest: $digest,
                error: $e,
                durationMs: round(microtime(true) * 1000 - $startMs, 2)
            );
            throw $e;
        }

        $this->writeRow(
            command: $command,
            actorId: $actor->id,
            status: 'success',
            digest: $digest,
            error: null,
            durationMs: round(microtime(true) * 1000 - $startMs, 2)
        );

        return $result;
    }

    private function writeRow(
        object $command,
        int $actorId,
        string $status,
        string $digest,
        ?\Throwable $error,
        float $durationMs
    ): void {
        $db = $this->db ?? Database::connect();

        try {
            $db->table('audit_log')->insert([
                'command_class' => $command::class,
                'actor_id' => $actorId,
                'tenant_id' => null, // populated when tenant resolver lands
                'correlation_id' => CorrelationIdService::get(),
                'status' => $status,
                'payload_digest' => $digest,
                'error_class' => $error !== null ? $error::class : null,
                'error_message' => $error?->getMessage(),
                'duration_ms' => $durationMs,
                'occurred_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $writeError) {
            $this->logger->error('Audit log write failed', [
                'component' => 'AuditMiddleware',
                'command_class' => $command::class,
                'audit_write_exception' => $writeError->getMessage(),
                'original_status' => $status,
                'correlation_id' => CorrelationIdService::get(),
            ]);
        }
    }

    /**
     * Build a stable digest of the command's public properties after redacting
     * sensitive fields. Uses {@see RedactingProcessor} indirectly via the same
     * key allowlist by piggy-backing on the processor's contract.
     */
    private function digestOf(object $command): string
    {
        $payload = $this->extractPublicState($command);

        // Recursively mask sensitive keys before hashing so the digest stays
        // stable across password rotations and so the digest reveals nothing.
        $redacted = $this->redact($payload);

        $json = json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '';
        }

        return hash('sha256', $json);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPublicState(object $command): array
    {
        $reflection = new \ReflectionObject($command);
        $out = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($command);
            $out[$prop->getName()] = $this->normaliseForJson($value);
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    private function redact(array $data): array
    {
        // Reuse the redaction logic by passing through a real LogRecord;
        // RedactingProcessor expects a Monolog record, so we replicate its
        // tiny key check here. The list MUST stay aligned with RedactingProcessor.
        static $sensitive = [
            'password', 'token', 'jwt', 'authorization', 'api_key',
            'secret', 'private_key', 'credit_card', 'card_number', 'cvv',
            'plaintext',
        ];

        $out = [];
        foreach ($data as $key => $value) {
            $needle = strtolower(is_string($key) ? $key : (string) $key);
            $isSensitive = false;
            foreach ($sensitive as $marker) {
                if (str_contains($needle, $marker)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $out[$key] = '***';
                continue;
            }

            if (is_array($value)) {
                $out[$key] = $this->redact($value);
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    private function normaliseForJson(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normaliseForJson($v);
            }
            return $out;
        }

        if (is_object($value)) {
            // For value objects with a public id (e.g. Actor) take the id.
            if (property_exists($value, 'id')) {
                return $value->id;
            }
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            return $value::class;
        }

        return null;
    }
}
