<?php

declare(strict_types = 1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\AccountsPayable;

use AbiSageIntacct\Contracts\HttpClientInterface;
use AbiSageIntacct\Modules\AccountsPayable\Vendors;
use PHPUnit\Framework\TestCase;

final class VendorsTest extends TestCase
{

    private HttpClientInterface $httpClientMock;
    private Vendors $vendors;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->vendors = new Vendors('/ap-vendors');
        $this->vendors->setHttpClient($this->httpClientMock);
    }

    public function test_list_vendors(): void
    {
        $expectedResponse = [
            'vendors' => [
                ['id' => '1', 'name' => 'Vendor 1'],
                ['id' => '2', 'name' => 'Vendor 2'],
            ],
        ];

        $this->httpClientMock->expects($this->once())
            ->method('get')
            ->with('/ap-vendors', [])
            ->willReturn($expectedResponse);

        $result = $this->vendors->list();

        $this->assertSame($expectedResponse, $result);
    }

    public function test_get_vendor(): void
    {
        $expectedResponse = [
            'id' => '1',
            'name' => 'Test Vendor',
            'email' => 'vendor@example.com',
        ];

        $this->httpClientMock->expects($this->once())
            ->method('get')
            ->with('/ap-vendors/1')
            ->willReturn($expectedResponse);

        $result = $this->vendors->get('1');

        $this->assertSame($expectedResponse, $result);
    }

    public function test_create_vendor(): void
    {
        $vendorData = [
            'name' => 'New Vendor',
            'email' => 'new@example.com',
        ];

        $expectedResponse = [
            'id' => '3',
            'name' => 'New Vendor',
            'email' => 'new@example.com',
        ];

        $this->httpClientMock->expects($this->once())
            ->method('post')
            ->with('/ap-vendors', $vendorData)
            ->willReturn($expectedResponse);

        $result = $this->vendors->create($vendorData);

        $this->assertSame($expectedResponse, $result);
    }

    public function test_update_vendor(): void
    {
        $vendorData = [
            'name' => 'Updated Vendor',
            'email' => 'updated@example.com',
        ];

        $expectedResponse = [
            'id' => '1',
            'name' => 'Updated Vendor',
            'email' => 'updated@example.com',
        ];

        $this->httpClientMock->expects($this->once())
            ->method('patch')
            ->with('/ap-vendors/1', $vendorData)
            ->willReturn($expectedResponse);

        $result = $this->vendors->update('1', $vendorData);

        $this->assertSame($expectedResponse, $result);
    }

    public function test_delete_vendor(): void
    {
        $expectedResponse = ['success' => true];

        $this->httpClientMock->expects($this->once())
            ->method('delete')
            ->with('/ap-vendors/1')
            ->willReturn($expectedResponse);

        $result = $this->vendors->delete('1');

        $this->assertSame($expectedResponse, $result);
    }

    public function test_get_vendor_payments(): void
    {
        $expectedResponse = [
            'payments' => [
                ['id' => '101', 'amount' => 1000],
                ['id' => '102', 'amount' => 500],
            ],
        ];

        $this->httpClientMock->expects($this->once())
            ->method('get')
            ->with('/ap-vendors/1/payments')
            ->willReturn($expectedResponse);

        $result = $this->vendors->getPayments('1');

        $this->assertSame($expectedResponse, $result);
    }

    public function test_get_vendor_contacts(): void
    {
        $expectedResponse = [
            'contacts' => [
                ['id' => '201', 'name' => 'John Doe', 'email' => 'john@vendor.com'],
                ['id' => '202', 'name' => 'Jane Smith', 'email' => 'jane@vendor.com'],
            ],
        ];

        $this->httpClientMock->expects($this->once())
            ->method('get')
            ->with('/ap-vendors/1/contacts')
            ->willReturn($expectedResponse);

        $result = $this->vendors->getContacts('1');

        $this->assertSame($expectedResponse, $result);
    }

    public function test_get_vendor_totals(): void
    {
        $expectedResponse = [
            'totalInvoices' => 5000,
            'totalPaid' => 3000,
            'totalDue' => 2000,
        ];

        $this->httpClientMock->expects($this->once())
            ->method('get')
            ->with('/ap-vendors/1/totals')
            ->willReturn($expectedResponse);

        $result = $this->vendors->getTotals('1');

        $this->assertSame($expectedResponse, $result);
    }

}
