<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Logging;

use App\Infrastructure\Logging\CorrelationIdMiddleware;
use App\Infrastructure\Logging\CorrelationIdService;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\UnitTestCase;

#[AllowMockObjectsWithoutExpectations]
final class CorrelationIdMiddlewareTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CorrelationIdService::clear();
    }

    protected function tearDown(): void
    {
        CorrelationIdService::clear();
        parent::tearDown();
    }

    public function test_adopts_valid_inbound_header(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getHeaderLine')
            ->with(CorrelationIdMiddleware::HEADER)
            ->willReturn('inbound-abc-123');

        (new CorrelationIdMiddleware())->before($request);

        $this->assertSame('inbound-abc-123', CorrelationIdService::get());
    }

    public function test_rejects_too_short_header_and_generates_fresh(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getHeaderLine')->willReturn('short');

        (new CorrelationIdMiddleware())->before($request);

        $id = CorrelationIdService::get();
        $this->assertNotSame('short', $id);
        $this->assertGreaterThanOrEqual(8, strlen($id));
    }

    public function test_rejects_header_with_invalid_chars(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getHeaderLine')->willReturn('this has spaces and !');

        (new CorrelationIdMiddleware())->before($request);

        $this->assertNotSame('this has spaces and !', CorrelationIdService::get());
    }

    public function test_after_filter_echoes_correlation_id_on_response(): void
    {
        CorrelationIdService::set('echo-test-1234');

        $request = $this->createMock(RequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $response->expects($this->once())
            ->method('setHeader')
            ->with(CorrelationIdMiddleware::HEADER, 'echo-test-1234')
            ->willReturnSelf();

        (new CorrelationIdMiddleware())->after($request, $response);
    }

    public function test_generates_id_when_no_inbound_header(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');

        (new CorrelationIdMiddleware())->before($request);

        $this->assertNotSame('', CorrelationIdService::get());
    }
}
