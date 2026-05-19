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
}
