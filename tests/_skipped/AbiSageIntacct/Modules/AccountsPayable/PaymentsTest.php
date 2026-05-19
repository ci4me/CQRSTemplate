<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\AccountsPayable;

use AbiSageIntacct\Contracts\HttpClientInterface;
use AbiSageIntacct\Modules\AccountsPayable\Payments;
use PHPUnit\Framework\TestCase;

final class PaymentsTest extends TestCase
{
    private HttpClientInterface $httpClientMock;
    private Payments $payments;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->payments = new Payments('/ap-payments');
        $this->payments->setHttpClient($this->httpClientMock);
    }

    public function test_list_payments(): void
    {
        $expectedResponse = [
            'payments' => [
                ['id' => '1', 'amount' => 500, 'date' => '2025-10-01'],
                ['id' => '2', 'amount' => 750, 'date' => '2025-10-15'],
            ],
        ];

        $this->httpClientMock->expects($this->once())
            ->method('get')
            ->with('/ap-payments', [])
            ->willReturn($expectedResponse);

        $result = $this->payments->list();

        $this->assertSame($expectedResponse, $result);
    }

    public function test_get_payment(): void
    {
        $expectedResponse = [
            'id' => '1',
            'amount' => 500,
            'date' => '2025-10-01',
            'status' => 'posted',
        ];

        $this->httpClientMock->expects($this->once())
            ->method('get')
            ->with('/ap-payments/1')
            ->willReturn($expectedResponse);

        $result = $this->payments->get('1');

        $this->assertSame($expectedResponse, $result);
    }

    public function test_create_payment(): void
    {
        $paymentData = [
            'vendor_id' => '1',
            'amount' => 500,
            'date' => '2025-10-30',
        ];

        $expectedResponse = [
            'id' => '3',
            'vendor_id' => '1',
            'amount' => 500,
            'date' => '2025-10-30',
        ];

        $this->httpClientMock->expects($this->once())
            ->method('post')
            ->with('/ap-payments', $paymentData)
            ->willReturn($expectedResponse);

        $result = $this->payments->create($paymentData);

        $this->assertSame($expectedResponse, $result);
    }

    public function test_update_payment(): void
    {
        $paymentData = [
            'amount' => 600,
            'status' => 'reconciled',
        ];

        $expectedResponse = [
            'id' => '1',
            'amount' => 600,
            'status' => 'reconciled',
        ];

        $this->httpClientMock->expects($this->once())
            ->method('patch')
            ->with('/ap-payments/1', $paymentData)
            ->willReturn($expectedResponse);

        $result = $this->payments->update('1', $paymentData);

        $this->assertSame($expectedResponse, $result);
    }

    public function test_delete_payment(): void
    {
        $expectedResponse = ['success' => true];

        $this->httpClientMock->expects($this->once())
            ->method('delete')
            ->with('/ap-payments/1')
            ->willReturn($expectedResponse);

        $result = $this->payments->delete('1');

        $this->assertSame($expectedResponse, $result);
    }

    public function test_get_payment_invoices(): void
    {
        $expectedResponse = [
            'invoices' => [
                ['id' => '1', 'amount' => 300, 'status' => 'paid'],
                ['id' => '2', 'amount' => 200, 'status' => 'paid'],
            ],
        ];

        $this->httpClientMock->expects($this->once())
            ->method('get')
            ->with('/ap-payments/1/invoices')
            ->willReturn($expectedResponse);

        $result = $this->payments->getInvoices('1');

        $this->assertSame($expectedResponse, $result);
    }
}
