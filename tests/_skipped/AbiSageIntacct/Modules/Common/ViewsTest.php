<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\Common;

use AbiSageIntacct\Contracts\HttpClientInterface;
use AbiSageIntacct\Modules\Common\Views;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ViewsTest extends TestCase
{
    private Views $views;
    private HttpClientInterface $httpClientMock;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->views = new Views('/views');
        $this->views->setHttpClient($this->httpClientMock);
    }

    // ========================================
    // list() - List all views
    // ========================================

    public function testListReturnsAllViews(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => 'view-001',
                    'name' => 'All Customers',
                    'type' => 'system',
                    'objectType' => 'AR_CUSTOMER',
                ],
                [
                    'id' => 'view-002',
                    'name' => 'Active Customers',
                    'type' => 'user',
                    'objectType' => 'AR_CUSTOMER',
                    'owner' => 'user@example.com',
                ],
            ],
            'total' => 2,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/views', [])
            ->willReturn($mockResponse);

        $result = $this->views->list();

        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']);
    }

    public function testListWithFilters(): void
    {
        $filters = ['objectType' => 'AR_CUSTOMER', 'type' => 'system'];
        $mockResponse = [
            'data' => [
                [
                    'id' => 'view-001',
                    'name' => 'All Customers',
                    'type' => 'system',
                    'objectType' => 'AR_CUSTOMER',
                ],
            ],
            'total' => 1,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/views', $filters)
            ->willReturn($mockResponse);

        $result = $this->views->list($filters);

        $this->assertCount(1, $result['data']);
    }

    // ========================================
    // get() - Get single view
    // ========================================

    public function testGetReturnsViewById(): void
    {
        $mockResponse = [
            'id' => 'view-001',
            'name' => 'All Customers',
            'type' => 'system',
            'objectType' => 'AR_CUSTOMER',
            'description' => 'Default view showing all customer records',
            'columns' => ['id', 'name', 'email', 'status'],
            'sortBy' => 'name',
            'isDefault' => true,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/views/view-001')
            ->willReturn($mockResponse);

        $result = $this->views->get('view-001');

        $this->assertIsArray($result);
        $this->assertEquals('view-001', $result['id']);
        $this->assertEquals('All Customers', $result['name']);
        $this->assertEquals('system', $result['type']);
    }

    // ========================================
    // getSystemViews() - Get system views
    // ========================================

    public function testGetSystemViewsReturnsAllSystemViews(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => 'view-001',
                    'name' => 'All Customers',
                    'type' => 'system',
                    'objectType' => 'AR_CUSTOMER',
                    'isDefault' => true,
                ],
                [
                    'id' => 'view-002',
                    'name' => 'All Invoices',
                    'type' => 'system',
                    'objectType' => 'AR_INVOICE',
                    'isDefault' => true,
                ],
                [
                    'id' => 'view-003',
                    'name' => 'All Bank Accounts',
                    'type' => 'system',
                    'objectType' => 'CM_BANK_ACCOUNT',
                    'isDefault' => true,
                ],
            ],
            'total' => 3,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/views/system', [])
            ->willReturn($mockResponse);

        $result = $this->views->getSystemViews();

        $this->assertIsArray($result);
        $this->assertCount(3, $result['data']);
        $this->assertEquals('system', $result['data'][0]['type']);
        $this->assertEquals('system', $result['data'][1]['type']);
        $this->assertEquals('system', $result['data'][2]['type']);
    }

    public function testGetSystemViewsWithFilters(): void
    {
        $filters = ['objectType' => 'AR_CUSTOMER'];
        $mockResponse = [
            'data' => [
                [
                    'id' => 'view-001',
                    'name' => 'All Customers',
                    'type' => 'system',
                    'objectType' => 'AR_CUSTOMER',
                    'isDefault' => true,
                ],
            ],
            'total' => 1,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/views/system', $filters)
            ->willReturn($mockResponse);

        $result = $this->views->getSystemViews($filters);

        $this->assertCount(1, $result['data']);
        $this->assertEquals('AR_CUSTOMER', $result['data'][0]['objectType']);
    }

    // ========================================
    // getUserViews() - Get user-defined views
    // ========================================

    public function testGetUserViewsReturnsAllUserViews(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => 'view-user-001',
                    'name' => 'My Active Customers',
                    'type' => 'user',
                    'objectType' => 'AR_CUSTOMER',
                    'owner' => 'john.doe@example.com',
                    'createdAt' => '2025-10-20T08:00:00Z',
                ],
                [
                    'id' => 'view-user-002',
                    'name' => 'High Value Invoices',
                    'type' => 'user',
                    'objectType' => 'AR_INVOICE',
                    'owner' => 'jane.smith@example.com',
                    'createdAt' => '2025-10-15T14:30:00Z',
                ],
            ],
            'total' => 2,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/views/user', [])
            ->willReturn($mockResponse);

        $result = $this->views->getUserViews();

        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']);
        $this->assertEquals('user', $result['data'][0]['type']);
        $this->assertEquals('user', $result['data'][1]['type']);
    }

    public function testGetUserViewsWithFilters(): void
    {
        $filters = ['owner' => 'john.doe@example.com'];
        $mockResponse = [
            'data' => [
                [
                    'id' => 'view-user-001',
                    'name' => 'My Active Customers',
                    'type' => 'user',
                    'objectType' => 'AR_CUSTOMER',
                    'owner' => 'john.doe@example.com',
                    'createdAt' => '2025-10-20T08:00:00Z',
                ],
            ],
            'total' => 1,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/views/user', $filters)
            ->willReturn($mockResponse);

        $result = $this->views->getUserViews($filters);

        $this->assertCount(1, $result['data']);
        $this->assertEquals('john.doe@example.com', $result['data'][0]['owner']);
    }

    // ========================================
    // Integration tests
    // ========================================

    public function testRetrievingAllViewTypesSequence(): void
    {
        $allViewsResponse = [
            'data' => [
                ['id' => 'view-001', 'type' => 'system'],
                ['id' => 'view-002', 'type' => 'user'],
                ['id' => 'view-003', 'type' => 'system'],
            ],
            'total' => 3,
        ];

        $systemViewsResponse = [
            'data' => [
                ['id' => 'view-001', 'type' => 'system'],
                ['id' => 'view-003', 'type' => 'system'],
            ],
            'total' => 2,
        ];

        $userViewsResponse = [
            'data' => [
                ['id' => 'view-002', 'type' => 'user'],
            ],
            'total' => 1,
        ];

        $this->httpClientMock
            ->expects($this->exactly(3))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $allViewsResponse,
                $systemViewsResponse,
                $userViewsResponse
            );

        // Get all views
        $all = $this->views->list();
        $this->assertCount(3, $all['data']);

        // Get system views
        $system = $this->views->getSystemViews();
        $this->assertCount(2, $system['data']);

        // Get user views
        $user = $this->views->getUserViews();
        $this->assertCount(1, $user['data']);
    }

    public function testViewDetailsRetrieval(): void
    {
        $viewDetailsResponse = [
            'id' => 'view-001',
            'name' => 'All Customers',
            'type' => 'system',
            'objectType' => 'AR_CUSTOMER',
            'columns' => ['id', 'name', 'email', 'phone', 'status'],
            'filters' => [
                ['field' => 'status', 'operator' => 'equals', 'value' => 'active'],
            ],
            'sortBy' => [
                ['field' => 'name', 'direction' => 'asc'],
            ],
            'isDefault' => true,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/views/view-001')
            ->willReturn($viewDetailsResponse);

        $result = $this->views->get('view-001');

        $this->assertEquals('view-001', $result['id']);
        $this->assertCount(5, $result['columns']);
        $this->assertCount(1, $result['filters']);
        $this->assertTrue($result['isDefault']);
    }
}
