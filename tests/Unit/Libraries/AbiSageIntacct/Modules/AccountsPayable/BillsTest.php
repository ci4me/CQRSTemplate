<?php

declare(strict_types = 1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\AccountsPayable;

use AbiSageIntacct\Contracts\HttpClientInterface;
use AbiSageIntacct\Modules\AccountsPayable\Bills;
use PHPUnit\Framework\TestCase;

final class BillsTest extends TestCase
{

    private HttpClientInterface $httpClientMock;
    private Bills $bills;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bills = new Bills('/ap-bills');
        $this->bills->setHttpClient($this->httpClientMock);
    }

    public function test_list_bills(): void
    {
        $expectedResponse = [
            'bills' => [
                ['id' => '1', 'vendor_id' => '1', 'amount' => 1000],
                ['id' => '2', 'vendor_id' => '2', 'amount' => 2000],
            ],
        ];

        $this->httpClientMock->expects($this->once())
            ->method('get')
            ->with('/ap-bills', [])
            ->willReturn($expectedResponse);

        $result = $this->bills->list();

        $this->assertSame($expectedResponse, $result);
    }

    public function test_get_bill(): void
    {
        $expectedResponse = [
            'id' => '1',
            'vendor_id' => '1',
            'amount' => 1000,
            'status' => 'open',
        ];

        $this->httpClientMock->expects($this->once())
            ->method('get')
            ->with('/ap-bills/1')
            ->willReturn($expectedResponse);

        $result = $this->bills->get('1');

        $this->assertSame($expectedResponse, $result);
    }

    public function test_create_bill(): void
    {
        $billData = [
            'vendor_id' => '1',
            'amount' => 1000,
            'due_date' => '2025-11-30',
        ];

        $expectedResponse = [
            'id' => '3',
            'vendor_id' => '1',
            'amount' => 1000,
            'due_date' => '2025-11-30',
        ];

        $this->httpClientMock->expects($this->once())
            ->method('post')
            ->with('/ap-bills', $billData)
            ->willReturn($expectedResponse);

        $result = $this->bills->create($billData);

        $this->assertSame($expectedResponse, $result);
    }

    public function test_update_bill(): void
    {
        $billData = [
            'amount' => 1500,
            'status' => 'closed',
        ];

        $expectedResponse = [
            'id' => '1',
            'vendor_id' => '1',
            'amount' => 1500,
            'status' => 'closed',
        ];

        $this->httpClientMock->expects($this->once())
            ->method('patch')
            ->with('/ap-bills/1', $billData)
            ->willReturn($expectedResponse);

        $result = $this->bills->update('1', $billData);

        $this->assertSame($expectedResponse, $result);
    }

    public function test_delete_bill(): void
    {
        $expectedResponse = ['success' => true];

        $this->httpClientMock->expects($this->once())
            ->method('delete')
            ->with('/ap-bills/1')
            ->willReturn($expectedResponse);

        $result = $this->bills->delete('1');

        $this->assertSame($expectedResponse, $result);
    }

    public function test_get_bill_lines(): void
    {
        $expectedResponse = [
            'lines' => [
                ['id' => '1', 'description' => 'Item 1', 'amount' => 500],
                ['id' => '2', 'description' => 'Item 2', 'amount' => 500],
            ],
        ];

        $this->httpClientMock->expects($this->once())
            ->method('get')
            ->with('/ap-bills/1/lines')
            ->willReturn($expectedResponse);

        $result = $this->bills->getLines('1');

        $this->assertSame($expectedResponse, $result);
    }

    public function test_get_bill_payments(): void
    {
        $expectedResponse = [
            'payments' => [
                ['id' => '101', 'amount' => 500, 'date' => '2025-10-15'],
                ['id' => '102', 'amount' => 500, 'date' => '2025-10-30'],
            ],
        ];

        $this->httpClientMock->expects($this->once())
            ->method('get')
            ->with('/ap-bills/1/payments')
            ->willReturn($expectedResponse);

        $result = $this->bills->getPayments('1');

        $this->assertSame($expectedResponse, $result);
    }

}
