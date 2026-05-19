<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Queries;

use App\Domain\Cookie\Queries\GetAllCookies\GetAllCookiesHandler;
use App\Domain\Cookie\Queries\GetAllCookies\GetAllCookiesQuery;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Infrastructure\Logging\LoggerFactory;
use Config\Logging;
use Tests\Support\Factories\CookieFactory;
use Tests\Support\UnitTestCase;

final class GetAllCookiesHandlerTest extends UnitTestCase
{
    private CookieRepositoryInterface $repository;
    private GetAllCookiesHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(CookieRepositoryInterface::class);
        $logger = LoggerFactory::create('test.cookie.queries');
        $loggingConfig = new Logging();
        $this->handler = new GetAllCookiesHandler($this->repository, $logger, $loggingConfig);
    }

    public function test_returns_all_active_cookies_by_default(): void
    {
        $query = new GetAllCookiesQuery();
        $expected = CookieFactory::createMultiple(3);

        $this->repository->expects($this->once())
            ->method('findAll')
            ->with(false)
            ->willReturn($expected);

        $result = $this->handler->handle($query);

        $this->assertCount(3, $result);
        $this->assertEquals($expected, $result);
    }

    public function test_returns_all_cookies_including_inactive(): void
    {
        $query = new GetAllCookiesQuery(includeInactive: true);
        $expected = CookieFactory::createMultiple(5);

        $this->repository->expects($this->once())
            ->method('findAll')
            ->with(true)
            ->willReturn($expected);

        $result = $this->handler->handle($query);

        $this->assertCount(5, $result);
    }

    public function test_returns_empty_array_when_no_cookies(): void
    {
        $query = new GetAllCookiesQuery();

        $this->repository->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $result = $this->handler->handle($query);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
