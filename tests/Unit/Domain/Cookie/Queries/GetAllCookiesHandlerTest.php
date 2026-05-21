<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Queries;

use App\Domain\Cookie\DTOs\CookieDTO;
use App\Domain\Cookie\Ports\CookieQueryRepositoryInterface;
use App\Domain\Cookie\Queries\GetAllCookies\GetAllCookiesHandler;
use App\Domain\Cookie\Queries\GetAllCookies\GetAllCookiesQuery;
use App\Infrastructure\Logging\LoggerFactory;
use Config\Logging;
use Tests\Support\UnitTestCase;

final class GetAllCookiesHandlerTest extends UnitTestCase
{
    private CookieQueryRepositoryInterface $repository;
    private GetAllCookiesHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(CookieQueryRepositoryInterface::class);
        $logger = LoggerFactory::create('test.cookie.queries');
        $loggingConfig = new Logging();
        $this->handler = new GetAllCookiesHandler($this->repository, $logger, $loggingConfig);
    }

    public function test_returns_all_active_cookies_by_default(): void
    {
        $query = new GetAllCookiesQuery();
        $dtos = $this->makeDtos(3);

        $this->repository->expects($this->once())
            ->method('findAll')
            ->with(false)
            ->willReturn($dtos);

        $result = $this->handler->handle($query);

        $this->assertCount(3, $result);
        $this->assertContainsOnlyInstancesOf(CookieDTO::class, $result);
    }

    public function test_returns_all_cookies_including_inactive(): void
    {
        $query = new GetAllCookiesQuery(includeInactive: true);
        $dtos = $this->makeDtos(5);

        $this->repository->expects($this->once())
            ->method('findAll')
            ->with(true)
            ->willReturn($dtos);

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

    /**
     * @return list<CookieDTO>
     */
    private function makeDtos(int $count): array
    {
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = new CookieDTO(
                id: $i,
                name: "Cookie {$i}",
                description: null,
                price: '1.00',
                formattedPrice: '$1.00',
                stock: 10,
                isActive: true,
                createdAt: '2025-10-21 10:00:00',
                updatedAt: null
            );
        }
        return $out;
    }
}
