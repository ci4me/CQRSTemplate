<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Logging;

use App\Infrastructure\Logging\CorrelationIdService;
use App\Infrastructure\Logging\LoggerFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Support\UnitTestCase;

/**
 * Drives LoggerFactory's processor wiring: CQRS context extraction from the
 * channel name, correlation-id auto-injection, and default level wiring.
 *
 * The factory normally writes to a rotating file. We swap the production
 * handlers out for a TestHandler so assertions can inspect log records
 * directly.
 */
final class LoggerFactoryTest extends UnitTestCase
{
    public function test_create_returns_a_monolog_logger_with_the_given_channel(): void
    {
        $logger = LoggerFactory::create('cookie.command.create');

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertSame('cookie.command.create', $logger->getName());
    }

    public function test_create_with_default_level_emits_info(): void
    {
        $logger = LoggerFactory::create('test.command.default');
        $this->swapInTestHandler($logger);

        $logger->info('hi');

        /** @var TestHandler $handler */
        $handler = $logger->getHandlers()[0];
        $this->assertTrue($handler->hasInfoRecords());
    }

    public function test_cqrs_processor_extracts_domain_and_command(): void
    {
        $logger = LoggerFactory::create('cookie.command.create');
        $handler = $this->swapInTestHandler($logger);

        $logger->info('creating');

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $extra = $records[0]->extra;
        $this->assertArrayHasKey('cqrs', $extra);
        $this->assertSame(['domain' => 'cookie', 'command' => 'create'], $extra['cqrs']);
    }

    public function test_cqrs_processor_extracts_query_type(): void
    {
        $logger = LoggerFactory::create('cookie.query.getById');
        $handler = $this->swapInTestHandler($logger);

        $logger->info('reading');

        $records = $handler->getRecords();
        $this->assertSame(['domain' => 'cookie', 'query' => 'getById'], $records[0]->extra['cqrs']);
    }

    public function test_cqrs_processor_extracts_event_type(): void
    {
        $logger = LoggerFactory::create('cookie.event.created');
        $handler = $this->swapInTestHandler($logger);

        $logger->info('handled');

        $records = $handler->getRecords();
        $this->assertSame(['domain' => 'cookie', 'event' => 'created'], $records[0]->extra['cqrs']);
    }

    public function test_cqrs_processor_returns_record_unchanged_for_single_segment_channel(): void
    {
        $logger = LoggerFactory::create('singleton');
        $handler = $this->swapInTestHandler($logger);

        $logger->info('hi');

        $records = $handler->getRecords();
        $this->assertArrayNotHasKey('cqrs', $records[0]->extra);
    }

    public function test_correlation_id_processor_auto_injects_id(): void
    {
        CorrelationIdService::set('cf-1234');
        $logger = LoggerFactory::create('cookie.command.create');
        $handler = $this->swapInTestHandler($logger);

        $logger->info('hi');

        $records = $handler->getRecords();
        $this->assertSame('cf-1234', $records[0]->extra['correlation_id']);
        CorrelationIdService::clear();
    }

    /**
     * Replace the rotating-file handler with a TestHandler so we can inspect
     * records without touching disk.
     */
    private function swapInTestHandler(Logger $logger): TestHandler
    {
        $handler = new TestHandler();
        // setHandlers replaces the existing handler stack.
        $logger->setHandlers([$handler]);
        return $handler;
    }
}
