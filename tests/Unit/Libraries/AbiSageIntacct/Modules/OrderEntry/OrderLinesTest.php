<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\OrderEntry;

use AbiSageIntacct\Http\CurlClient;
use AbiSageIntacct\Modules\OrderEntry\OrderLines;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class OrderLinesTest extends TestCase
{
    private OrderLines $orderLines;

    private CurlClient $httpClientMock;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(CurlClient::class);
        $this->orderLines = new OrderLines('/oe-order-lines');
        $this->orderLines->setHttpClient($this->httpClientMock);
    }

    public function testListReturnsAllOrderLines(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => 'LINE-001',
                    'itemId' => 'ITEM-001',
                    'itemName' => 'Widget A',
                    'lineTotal' => '1000.00',
                    'orderId' => 'ORD-001',
                    'quantity' => 10,
                    'unitPrice' => '100.00',
                ],
                [
                    'id' => 'LINE-002',
                    'itemId' => 'ITEM-002',
                    'itemName' => 'Widget B',
                    'lineTotal' => '1000.00',
                    'orderId' => 'ORD-001',
                    'quantity' => 5,
                    'unitPrice' => '200.00',
                ],
            ],
            'total' => 2,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/oe-order-lines', [])
            ->willReturn($mockResponse);

        $result = $this->orderLines->list();

        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']);
    }

    public function testListWithFilters(): void
    {
        $filters = ['orderId' => 'ORD-001', 'status' => 'open'];
        $mockResponse = [
            'data' => [
                [
                    'id' => 'LINE-001',
                    'itemId' => 'ITEM-001',
                    'itemName' => 'Widget A',
                    'lineTotal' => '1000.00',
                    'orderId' => 'ORD-001',
                    'quantity' => 10,
                    'unitPrice' => '100.00',
                ],
            ],
            'total' => 1,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/oe-order-lines', $filters)
            ->willReturn($mockResponse);

        $result = $this->orderLines->list($filters);

        $this->assertCount(1, $result['data']);
    }

    public function testGetReturnsOrderLineById(): void
    {
        $mockResponse = [
            'id' => 'LINE-001',
            'itemId' => 'ITEM-001',
            'itemName' => 'Widget A',
            'lineTotal' => '1000.00',
            'orderId' => 'ORD-001',
            'quantity' => 10,
            'status' => 'open',
            'unitPrice' => '100.00',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/oe-order-lines/LINE-001')
            ->willReturn($mockResponse);

        $result = $this->orderLines->get('LINE-001');

        $this->assertIsArray($result);
        $this->assertEquals('LINE-001', $result['id']);
        $this->assertEquals('Widget A', $result['itemName']);
    }

    public function testCreateNewOrderLine(): void
    {
        $orderLineData = [
            'itemId' => 'ITEM-003',
            'itemName' => 'Widget C',
            'orderId' => 'ORD-001',
            'quantity' => 15,
            'unitPrice' => '150.00',
        ];

        $mockResponse = [
            'id' => 'LINE-003',
            'itemId' => 'ITEM-003',
            'itemName' => 'Widget C',
            'lineTotal' => '2250.00',
            'orderId' => 'ORD-001',
            'quantity' => 15,
            'status' => 'open',
            'unitPrice' => '150.00',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('post')
            ->with('/oe-order-lines', $orderLineData)
            ->willReturn($mockResponse);

        $result = $this->orderLines->create($orderLineData);

        $this->assertIsArray($result);
        $this->assertEquals('LINE-003', $result['id']);
        $this->assertEquals('Widget C', $result['itemName']);
        $this->assertEquals('2250.00', $result['lineTotal']);
    }

    public function testUpdateOrderLine(): void
    {
        $orderLineData = [
            'quantity' => 20,
            'unitPrice' => '120.00',
        ];

        $mockResponse = [
            'id' => 'LINE-001',
            'itemId' => 'ITEM-001',
            'itemName' => 'Widget A',
            'lineTotal' => '2400.00',
            'orderId' => 'ORD-001',
            'quantity' => 20,
            'unitPrice' => '120.00',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('patch')
            ->with('/oe-order-lines/LINE-001', $orderLineData)
            ->willReturn($mockResponse);

        $result = $this->orderLines->update('LINE-001', $orderLineData);

        $this->assertIsArray($result);
        $this->assertEquals(20, $result['quantity']);
        $this->assertEquals('120.00', $result['unitPrice']);
        $this->assertEquals('2400.00', $result['lineTotal']);
    }

    public function testDeleteOrderLine(): void
    {
        $mockResponse = ['id' => 'LINE-001', 'status' => 'deleted'];

        $this->httpClientMock
            ->expects($this->once())
            ->method('delete')
            ->with('/oe-order-lines/LINE-001')
            ->willReturn($mockResponse);

        $result = $this->orderLines->delete('LINE-001');

        $this->assertIsArray($result);
        $this->assertEquals('deleted', $result['status']);
    }

}
