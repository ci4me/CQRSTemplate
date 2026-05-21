<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Queries;

use App\Domain\Cookie\DTOs\CookieDTO;
use App\Domain\Cookie\Ports\CookieQueryRepositoryInterface;
use App\Domain\Cookie\Queries\GetCookieById\GetCookieByIdHandler;
use App\Domain\Cookie\Queries\GetCookieById\GetCookieByIdQuery;
use App\Infrastructure\Logging\LoggerFactory;
use Config\Logging;
use Tests\Support\UnitTestCase;

final class GetCookieByIdHandlerTest extends UnitTestCase
{
    private CookieQueryRepositoryInterface $repository;
    private GetCookieByIdHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(CookieQueryRepositoryInterface::class);
        $logger = LoggerFactory::create('test.cookie.queries');
        $loggingConfig = new Logging();
        $this->handler = new GetCookieByIdHandler($this->repository, $logger, $loggingConfig);
    }

    public function test_returns_cookie_when_found(): void
    {
        $query = new GetCookieByIdQuery(id: 1);
        $expected = new CookieDTO(
            id: 1,
            name: 'Chip',
            description: 'A cookie',
            price: '2.99',
            formattedPrice: '$2.99',
            stock: 5,
            isActive: true,
            createdAt: '2025-10-21 10:00:00',
            updatedAt: null
        );

        $this->repository->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($expected);

        $result = $this->handler->handle($query);

        $this->assertInstanceOf(CookieDTO::class, $result);
        $this->assertSame(1, $result->id);
        $this->assertSame('Chip', $result->name);
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
