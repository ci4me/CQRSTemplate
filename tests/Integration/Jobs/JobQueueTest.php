<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Infrastructure\Jobs\JobQueue;
use App\Infrastructure\Jobs\JobWorker;
use Config\Database;
use Psr\Log\NullLogger;
use Tests\Support\IntegrationTestCase;

final class JobQueueTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestJobHandler::reset();
    }

    public function test_push_inserts_pending_job(): void
    {
        $queue = new JobQueue();
        $id = $queue->push(TestJobHandler::class, ['order_id' => 7]);

        $this->assertGreaterThan(0, $id);

        $row = Database::connect()->table('jobs')->where('id', $id)->get()->getRowArray();
        $this->assertSame('pending', $row['status']);
        $this->assertSame(TestJobHandler::class, $row['handler_class']);
        $this->assertSame(['order_id' => 7], json_decode($row['payload'], true));
        $this->assertSame('0', (string) $row['attempts']);
    }

    public function test_worker_runs_pending_job(): void
    {
        $queue = new JobQueue();
        $queue->push(TestJobHandler::class, ['email' => 'a@b.c']);

        $stats = (new JobWorker(new NullLogger()))->drain();

        $this->assertSame(1, $stats['processed']);
        $this->assertSame(1, $stats['succeeded']);
        $this->assertSame(1, TestJobHandler::$callCount);
        $this->assertSame([['email' => 'a@b.c']], TestJobHandler::$invocations);

        $row = Database::connect()->table('jobs')->get()->getRowArray();
        $this->assertSame('done', $row['status']);
    }

    public function test_worker_retries_after_failure(): void
    {
        TestJobHandler::$throwOnce = true;

        $queue = new JobQueue();
        $queue->push(TestJobHandler::class, ['x' => 1], maxAttempts: 3);

        $worker = new JobWorker(new NullLogger());
        $stats = $worker->drain();

        $this->assertSame(1, $stats['retried']);

        $row = Database::connect()->table('jobs')->get()->getRowArray();
        $this->assertSame('pending', $row['status']);
        $this->assertSame('1', (string) $row['attempts']);
        $this->assertStringContainsString('first attempt fails', (string) $row['last_error']);
    }

    public function test_worker_marks_job_failed_when_max_attempts_reached(): void
    {
        TestJobHandler::$alwaysThrow = true;

        $queue = new JobQueue();
        $queue->push(TestJobHandler::class, ['x' => 1], maxAttempts: 1);

        $worker = new JobWorker(new NullLogger());
        $stats = $worker->drain();

        $this->assertSame(1, $stats['failed']);

        $row = Database::connect()->table('jobs')->get()->getRowArray();
        $this->assertSame('failed', $row['status']);
    }

    public function test_worker_does_not_pick_up_delayed_jobs(): void
    {
        $queue = new JobQueue();
        $queue->push(TestJobHandler::class, ['x' => 1], delaySeconds: 600);

        $stats = (new JobWorker(new NullLogger()))->drain();

        $this->assertSame(0, $stats['processed']);
    }

    public function test_queues_are_isolated(): void
    {
        $queue = new JobQueue();
        $queue->push(TestJobHandler::class, ['x' => 'A'], queue: 'emails');
        $queue->push(TestJobHandler::class, ['x' => 'B'], queue: 'reports');

        $worker = new JobWorker(new NullLogger());
        $stats = $worker->drain('emails');

        $this->assertSame(1, $stats['processed']);
        $this->assertSame([['x' => 'A']], TestJobHandler::$invocations);
    }

    public function test_invalid_handler_class_marks_job_failed(): void
    {
        $queue = new JobQueue();
        $queue->push('App\\Nonexistent\\Handler', ['x' => 1], maxAttempts: 1);

        $stats = (new JobWorker(new NullLogger()))->drain();

        $this->assertSame(1, $stats['failed']);

        $row = Database::connect()->table('jobs')->get()->getRowArray();
        $this->assertStringContainsString('not found', (string) $row['last_error']);
    }
}
