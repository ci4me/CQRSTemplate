<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Queries;

use App\Domain\Cookie\DTOs\CookieDTO;
use App\Domain\Cookie\Queries\GetCookieById\GetCookieByIdHandler;
use App\Domain\Cookie\Queries\GetCookieById\GetCookieByIdQuery;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Infrastructure\Logging\LoggerFactory;
use Config\Logging;
use Tests\Support\Factories\CookieFactory;
use Tests\Support\UnitTestCase;

final class GetCookieByIdHandlerTest extends UnitTestCase
{
    private CookieRepositoryInterface $repository;
    private GetCookieByIdHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(CookieRepositoryInterface::class);
        $logger = LoggerFactory::create('test.cookie.queries');
        $loggingConfig = new Logging();
        $this->handler = new GetCookieByIdHandler($this->repository, $logger, $loggingConfig);
    }

    public function test_returns_cookie_when_found(): void
    {
        $query = new GetCookieByIdQuery(id: 1);
        $expected = CookieFactory::createPersistedCookie(['id' => 1]);

        $this->repository->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($expected);

        $result = $this->handler->handle($query);

        $this->assertInstanceOf(CookieDTO::class, $result);
        $this->assertEquals(1, $result->id);
    }

    public function test_returns_null_when_not_found(): void
    {
        $query = new GetCookieByIdQuery(id: 999);

        $this->repository->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $result = $this->handler->handle($query);

        $this->assertNull($result);
    }
}
