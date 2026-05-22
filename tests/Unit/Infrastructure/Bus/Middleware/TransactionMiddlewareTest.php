<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Bus\Middleware;

use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Bus\Middleware\TransactionMiddleware;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Drives the transaction lifecycle around the command pipeline:
 * commit on success, rollback on Throwable, rollback when transStatus
 * comes back false, and event-dispatcher strict-mode handling.
 */
final class TransactionMiddlewareTest extends CIUnitTestCase
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
        // CI4's BaseConnection caches _transStatus = false across tests once
        // any failing query has flipped it (see test_rolls_back_when_trans_status_is_false).
        // We reflectively reset transStatus to true before each test so the
        // success paths can begin with a clean transactional state.
        $db = Database::connect();
        $ref = new \ReflectionObject($db);
        if ($ref->hasProperty('transStatus')) {
            $prop = $ref->getProperty('transStatus');
            $prop->setAccessible(true);
            $prop->setValue($db, true);
        }
        if ($ref->hasProperty('transDepth')) {
            $depth = $ref->getProperty('transDepth');
            $depth->setAccessible(true);
            $depth->setValue($db, 0);
        }
    }

    public function test_commits_when_handler_succeeds(): void
    {
        $db = Database::connect();
        $mw = new TransactionMiddleware(new NullLogger(), $db);
        $marker = 'tx-commit-' . uniqid();

        $result = $mw->handle(new \stdClass(), static function () use ($db, $marker): string {
            $db->table('audit_log')->insert([
                'command_class' => $marker,
                'actor_id' => 0,
                'tenant_id' => null,
                'correlation_id' => 'tx-test',
                'status' => 'success',
                'payload_digest' => 'abc',
                'duration_ms' => 0.0,
                'occurred_at' => date('Y-m-d H:i:s'),
            ]);
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(1, $db->table('audit_log')->where('command_class', $marker)->countAllResults());
    }

    public function test_rolls_back_when_handler_throws(): void
    {
        $db = Database::connect();
        $mw = new TransactionMiddleware(new NullLogger(), $db);
        $marker = 'tx-rollback-' . uniqid();

        try {
            $mw->handle(new \stdClass(), static function () use ($db, $marker): never {
                $db->table('audit_log')->insert([
                    'command_class' => $marker,
                    'actor_id' => 0,
                    'tenant_id' => null,
                    'correlation_id' => 'tx-test',
                    'status' => 'success',
                    'payload_digest' => 'abc',
                    'duration_ms' => 0.0,
                    'occurred_at' => date('Y-m-d H:i:s'),
                ]);
                throw new RuntimeException('handler exploded');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('handler exploded', $e->getMessage());
        }

        $this->assertSame(
            0,
            $db->table('audit_log')->where('command_class', $marker)->countAllResults(),
            'rollback must undo the row insert',
        );
    }

    public function test_rolls_back_when_trans_status_is_false(): void
    {
        $db = Database::connect();
        $mw = new TransactionMiddleware(new NullLogger(), $db);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Transaction failed during');

        $mw->handle(new \stdClass(), static function () use ($db): string {
            // Run an intentionally broken query so CI4 flips transStatus to false
            // without throwing (transException is off by default in tests).
            $db->query('UPDATE nonexistent_table_xyz SET x = 1 WHERE y = 2');
            return 'ok';
        });
    }

    public function test_dispatcher_resolver_toggles_strict_event_mode(): void
    {
        $db = Database::connect();
        $dispatcher = new EventDispatcher();

        $observedDuringHandler = null;
        $mw = new TransactionMiddleware(
            new NullLogger(),
            $db,
            static fn(): EventDispatcher => $dispatcher,
        );

        $mw->handle(new \stdClass(), static function () use ($dispatcher, &$observedDuringHandler): string {
            // The middleware should have flipped strict mode ON for the handler.
            // setRethrowOnListenerFailure returns the previous value, so
            // re-toggling it back to its current state lets us inspect it.
            $observedDuringHandler = $dispatcher->setRethrowOnListenerFailure(true);
            return 'ok';
        });

        $this->assertTrue($observedDuringHandler, 'strict mode must be ON during handler');

        // After handle() returns, the previous value (false) must be restored.
        $afterRestore = $dispatcher->setRethrowOnListenerFailure(true);
        $this->assertFalse($afterRestore, 'strict mode must be restored after handle()');
    }

    public function test_dispatcher_resolver_returning_null_is_handled(): void
    {
        $db = Database::connect();
        $mw = new TransactionMiddleware(
            new NullLogger(),
            $db,
            static fn(): ?EventDispatcher => null,
        );

        $result = $mw->handle(new \stdClass(), static fn(): int => 42);

        $this->assertSame(42, $result);
    }
}
