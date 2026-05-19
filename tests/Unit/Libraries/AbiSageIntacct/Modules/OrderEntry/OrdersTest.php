<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\OrderEntry;

use AbiSageIntacct\Http\CurlClient;
use AbiSageIntacct\Modules\OrderEntry\Orders;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class OrdersTest extends TestCase
{
    private Orders $orders;

    private CurlClient $httpClientMock;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(CurlClient::class);
        $this->orders = new Orders('/oe-orders');
        $this->orders->setHttpClient($this->httpClientMock);
    }

    public function testListReturnsAllOrders(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'customerName' => 'Acme Corp',
                    'id' => 'ORD-001',
                    'orderDate' => '2025-10-30',
                    'status' => 'open',
                    'total' => '5000.00',
                ],
                [
                    'customerName' => 'Tech Solutions',
                    'id' => 'ORD-002',
                    'orderDate' => '2025-10-29',
                    'status' => 'open',
                    'total' => '3500.00',
                ],
            ],
            'total' => 2,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/oe-orders', [])
            ->willReturn($mockResponse);

        $result = $this->orders->list();

        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']);
    }

    public function testListWithFilters(): void
    {
        $filters = ['customerName' => 'Acme', 'status' => 'open'];
        $mockResponse = [
            'data' => [
                [
                    'customerName' => 'Acme Corp',
                    'id' => 'ORD-001',
                    'orderDate' => '2025-10-30',
                    'status' => 'open',
                    'total' => '5000.00',
                ],
            ],
            'total' => 1,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/oe-orders', $filters)
            ->willReturn($mockResponse);

        $result = $this->orders->list($filters);

        $this->assertCount(1, $result['data']);
    }

    public function testGetReturnsOrderById(): void
    {
        $mockResponse = [
            'currency' => 'USD',
            'customerName' => 'Acme Corp',
            'id' => 'ORD-001',
            'orderDate' => '2025-10-30',
            'status' => 'open',
            'total' => '5000.00',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/oe-orders/ORD-001')
            ->willReturn($mockResponse);

        $result = $this->orders->get('ORD-001');

        $this->assertIsArray($result);
        $this->assertEquals('ORD-001', $result['id']);
        $this->assertEquals('Acme Corp', $result['customerName']);
    }

    public function testCreateNewOrder(): void
    {
        $orderData = [
            'currency' => 'USD',
            'customerId' => 'CUST-123',
            'customerName' => 'New Customer',
            'items' => [
                [
                    'itemId' => 'ITEM-001',
                    'quantity' => 10,
                    'unitPrice' => '100.00',
                ],
            ],
            'orderDate' => '2025-10-30',
        ];

        $mockResponse = [
            'customerId' => 'CUST-123',
            'customerName' => 'New Customer',
            'id' => 'ORD-003',
            'orderDate' => '2025-10-30',
            'status' => 'open',
            'total' => '1000.00',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('post')
            ->with('/oe-orders', $orderData)
            ->willReturn($mockResponse);

        $result = $this->orders->create($orderData);

        $this->assertIsArray($result);
        $this->assertEquals('ORD-003', $result['id']);
        $this->assertEquals('New Customer', $result['customerName']);
    }

    public function testUpdateOrder(): void
    {
        $orderData = ['status' => 'partially_shipped'];

        $mockResponse = [
            'customerName' => 'Acme Corp',
            'id' => 'ORD-001',
            'orderDate' => '2025-10-30',
            'status' => 'partially_shipped',
            'total' => '5000.00',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('patch')
            ->with('/oe-orders/ORD-001', $orderData)
            ->willReturn($mockResponse);

        $result = $this->orders->update('ORD-001', $orderData);

        $this->assertIsArray($result);
        $this->assertEquals('partially_shipped', $result['status']);
    }

    public function testDeleteOrder(): void
    {
        $mockResponse = ['id' => 'ORD-001', 'status' => 'deleted'];

        $this->httpClientMock
            ->expects($this->once())
            ->method('delete')
            ->with('/oe-orders/ORD-001')
            ->willReturn($mockResponse);

        $result = $this->orders->delete('ORD-001');

        $this->assertIsArray($result);
        $this->assertEquals('deleted', $result['status']);
    }

    public function testGetLinesReturnsOrderLines(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'itemId' => 'ITEM-001',
                    'itemName' => 'Widget A',
                    'lineId' => 'LINE-001',
                    'lineTotal' => '1000.00',
                    'quantity' => 10,
                    'unitPrice' => '100.00',
                ],
                [
                    'itemId' => 'ITEM-002',
                    'itemName' => 'Widget B',
                    'lineId' => 'LINE-002',
                    'lineTotal' => '1000.00',
                    'quantity' => 5,
                    'unitPrice' => '200.00',
                ],
            ],
            'total' => 2,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/oe-orders/ORD-001/lines')
            ->willReturn($mockResponse);

        $result = $this->orders->getLines('ORD-001');

        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']);
        $this->assertEquals('LINE-001', $result['data'][0]['lineId']);
    }

    public function testShipOrder(): void
    {
        $mockResponse = [
            'id' => 'ORD-001',
            'shipDate' => '2025-10-30',
            'status' => 'shipped',
            'trackingNumber' => 'TRACK-123456',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('post')
            ->with('/oe-orders/ORD-001/ship', [])
            ->willReturn($mockResponse);

        $result = $this->orders->ship('ORD-001');

        $this->assertIsArray($result);
        $this->assertEquals('shipped', $result['status']);
        $this->assertEquals('TRACK-123456', $result['trackingNumber']);
    }

    public function testCancelOrder(): void
    {
        $mockResponse = [
            'cancelDate' => '2025-10-30',
            'cancelReason' => 'Customer request',
            'id' => 'ORD-001',
            'status' => 'cancelled',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('post')
            ->with('/oe-orders/ORD-001/cancel', [])
            ->willReturn($mockResponse);

        $result = $this->orders->cancel('ORD-001');

        $this->assertIsArray($result);
        $this->assertEquals('cancelled', $result['status']);
        $this->assertEquals('Customer request', $result['cancelReason']);
    }

}
