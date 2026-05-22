<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Bus\Middleware;

use App\Domain\Shared\ValueObjects\Actor;
use App\Infrastructure\Auth\Services\ActorResolver;
use App\Infrastructure\Bus\Middleware\AuditMiddleware;
use App\Infrastructure\Logging\CorrelationIdService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Psr\Log\NullLogger;

/**
 * AuditMiddleware test uses a real in-memory SQLite database via the
 * CIUnitTestCase + DatabaseTestTrait, which is the same setup the rest of
 * the integration suite uses. Pure unit testing is not viable here because
 * the middleware reaches into BaseConnection::table()->insert(), which is
 * not a stable boundary to mock.
 */
final class AuditMiddlewareTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    /** @var bool */
    protected $migrate = true;
    /** @var bool */
    protected $refresh = true;
    /** @var string|null */
    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();
        CorrelationIdService::clear();
        CorrelationIdService::set('test-correlation-id');
    }

    protected function tearDown(): void
    {
        CorrelationIdService::clear();
        parent::tearDown();
    }

    public function test_writes_success_row_when_command_succeeds(): void
    {
        $command = new class {
            public int $cookieId = 7;
        };

        // No request available in this CLI test; resolver defaults to system actor.
        $mw = new AuditMiddleware(new NullLogger(), new ActorResolver());

        $result = $mw->handle($command, static fn(): string => 'ok');

        $this->assertSame('ok', $result);
        $this->seeInDatabase('audit_log', [
            'actor_id' => Actor::SYSTEM_ID,
            'status' => 'success',
            'correlation_id' => 'test-correlation-id',
        ]);
    }

    public function test_writes_failure_row_and_rethrows_when_handler_throws(): void
    {
        $command = new class {
            public string $value = 'test';
        };

        $mw = new AuditMiddleware(new NullLogger(), new ActorResolver());

        try {
            $mw->handle($command, static function (): never {
                throw new \RuntimeException('handler boom');
            });
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('handler boom', $e->getMessage());
        }

        $this->seeInDatabase('audit_log', [
            'status' => 'failure',
            'error_class' => \RuntimeException::class,
            'error_message' => 'handler boom',
        ]);
    }

    public function test_sensitive_fields_do_not_appear_in_digest(): void
    {
        // Two commands with identical non-sensitive fields but different
        // passwords MUST produce the SAME digest after redaction.
        $a = new class {
            public string $email = 'a@b.c';
            public string $password = 'one';
        };
        $b = new class {
            public string $email = 'a@b.c';
            public string $password = 'two';
        };

        $mw = new AuditMiddleware(new NullLogger(), new ActorResolver());
        $mw->handle($a, static fn(): bool => true);
        $mw->handle($b, static fn(): bool => true);

        $rows = Database::connect()
            ->table('audit_log')
            ->select('payload_digest')
            ->get()
            ->getResultArray();

        $this->assertCount(2, $rows);
        $this->assertSame($rows[0]['payload_digest'], $rows[1]['payload_digest']);
    }

    public function test_digest_normalises_objects_arrays_and_value_objects(): void
    {
        // Cover normaliseForJson branches: object with public id, object with
        // __toString, plain object, and recursive array handling.
        $command = new AuditCommandWithMixedTypes();

        $mw = new AuditMiddleware(new NullLogger(), new ActorResolver());
        $mw->handle($command, static fn(): bool => true);

        $rows = Database::connect()
            ->table('audit_log')
            ->select('payload_digest, status')
            ->get()
            ->getResultArray();

        $this->assertCount(1, $rows);
        $this->assertSame('success', $rows[0]['status']);
        $this->assertNotEmpty($rows[0]['payload_digest']);
    }

    public function test_audit_write_failure_is_logged_and_does_not_break_command(): void
    {
        // Force the audit row to fail by closing the db connection after the
        // command runs but before the audit insert. Simpler: subclass to
        // simulate the failure path via a custom logger that captures writes.
        $captured = [];
        $logger = new class ($captured) extends NullLogger {
            /**
             * @param array<int, array{level: string, message: string, context: array<string, mixed>}> $captured
             */
            public function __construct(private array &$captured)
            {
            }
            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->captured[] = ['level' => 'error', 'message' => (string) $message, 'context' => $context];
            }
        };
        $mw = new AuditMiddleware($logger, new ActorResolver());

        // Run a command first, then DROP the audit_log table so the next
        // insert fails. The middleware MUST swallow the error.
        $mw->handle(new class {
            public string $name = 'first';
        }, static fn(): bool => true);

        Database::connect()->query('DROP TABLE audit_log');

        $result = $mw->handle(new class {
            public string $name = 'second';
        }, static fn(): string => 'ok');

        $this->assertSame('ok', $result);
        $this->assertNotEmpty($captured, 'expected audit write failure to be logged');
        $this->assertSame('Audit log write failed', $captured[0]['message']);
    }
}

/**
 * Test fixture exercising normaliseForJson's object/array branches.
 */
final class AuditCommandWithMixedTypes
{
    public int $cookieId = 5;
    /** @var array{nested: array{a: int}} */
    public array $nested = ['nested' => ['a' => 1]];
    public AuditObjectWithId $entity;
    public AuditObjectStringable $stringable;
    public AuditObjectPlain $plain;

    public function __construct()
    {
        $this->entity = new AuditObjectWithId();
        $this->stringable = new AuditObjectStringable();
        $this->plain = new AuditObjectPlain();
    }
}

final class AuditObjectWithId
{
    public int $id = 123;
}

final class AuditObjectStringable
{
    public function __toString(): string
    {
        return 'stringified';
    }
}

final class AuditObjectPlain
{
}
