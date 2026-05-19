<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\GeneralLedger;

use AbiSageIntacct\Contracts\HttpClientInterface;
use AbiSageIntacct\Modules\GeneralLedger\Accounts;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AccountsTest extends TestCase
{
    private Accounts $accounts;
    private HttpClientInterface $httpClientMock;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->accounts = new Accounts('/gl-accounts');
        $this->accounts->setHttpClient($this->httpClientMock);
    }

    // ========================================
    // list() - List all general ledger accounts
    // ========================================

    public function testListReturnsAllAccounts(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => '1000',
                    'code' => '1000',
                    'name' => 'Cash',
                    'type' => 'asset',
                    'status' => 'active',
                ],
                [
                    'id' => '2000',
                    'code' => '2000',
                    'name' => 'Accounts Payable',
                    'type' => 'liability',
                    'status' => 'active',
                ],
            ],
            'total' => 2,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/gl-accounts', [])
            ->willReturn($mockResponse);

        $result = $this->accounts->list();

        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']);
    }

    public function testListWithFilters(): void
    {
        $filters = ['type' => 'asset', 'status' => 'active'];
        $mockResponse = [
            'data' => [
                [
                    'id' => '1000',
                    'code' => '1000',
                    'name' => 'Cash',
                    'type' => 'asset',
                    'status' => 'active',
                ],
            ],
            'total' => 1,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/gl-accounts', $filters)
            ->willReturn($mockResponse);

        $result = $this->accounts->list($filters);

        $this->assertCount(1, $result['data']);
    }

    // ========================================
    // get() - Get single account
    // ========================================

    public function testGetReturnsAccountById(): void
    {
        $mockResponse = [
            'id' => '1000',
            'code' => '1000',
            'name' => 'Cash',
            'type' => 'asset',
            'status' => 'active',
            'balance' => '50000.00',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/gl-accounts/1000')
            ->willReturn($mockResponse);

        $result = $this->accounts->get('1000');

        $this->assertIsArray($result);
        $this->assertEquals('1000', $result['id']);
        $this->assertEquals('Cash', $result['name']);
    }

    // ========================================
    // create() - Create new account
    // ========================================

    public function testCreateNewAccount(): void
    {
        $accountData = [
            'code' => '3000',
            'name' => 'Revenue from Services',
            'type' => 'revenue',
            'currency' => 'USD',
        ];

        $mockResponse = [
            'id' => '3000',
            'code' => '3000',
            'name' => 'Revenue from Services',
            'type' => 'revenue',
            'currency' => 'USD',
            'status' => 'active',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('post')
            ->with('/gl-accounts', $accountData)
            ->willReturn($mockResponse);

        $result = $this->accounts->create($accountData);

        $this->assertIsArray($result);
        $this->assertEquals('3000', $result['id']);
        $this->assertEquals('Revenue from Services', $result['name']);
    }

    // ========================================
    // update() - Update account
    // ========================================

    public function testUpdateAccount(): void
    {
        $accountData = [
            'name' => 'Updated Account Name',
        ];

        $mockResponse = [
            'id' => '1000',
            'code' => '1000',
            'name' => 'Updated Account Name',
            'type' => 'asset',
            'status' => 'active',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('patch')
            ->with('/gl-accounts/1000', $accountData)
            ->willReturn($mockResponse);

        $result = $this->accounts->update('1000', $accountData);

        $this->assertIsArray($result);
        $this->assertEquals('Updated Account Name', $result['name']);
    }

    // ========================================
    // delete() - Delete account
    // ========================================

    public function testDeleteAccount(): void
    {
        $mockResponse = [
            'id' => '1000',
            'status' => 'deleted',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('delete')
            ->with('/gl-accounts/1000')
            ->willReturn($mockResponse);

        $result = $this->accounts->delete('1000');

        $this->assertIsArray($result);
        $this->assertEquals('deleted', $result['status']);
    }

    // ========================================
    // getBalance() - Get account balance
    // ========================================

    public function testGetBalanceReturnsAccountBalance(): void
    {
        $mockResponse = [
            'accountId' => '1000',
            'accountCode' => '1000',
            'accountName' => 'Cash',
            'balance' => '125000.50',
            'currency' => 'USD',
            'lastUpdated' => '2025-10-30T10:30:00Z',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/gl-accounts/1000/balance')
            ->willReturn($mockResponse);

        $result = $this->accounts->getBalance('1000');

        $this->assertIsArray($result);
        $this->assertEquals('1000', $result['accountId']);
        $this->assertEquals('125000.50', $result['balance']);
        $this->assertEquals('USD', $result['currency']);
    }

    // ========================================
    // getTransactions() - Get account transactions
    // ========================================

    public function testGetTransactionsReturnsAccountTransactions(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => 'je-001',
                    'debit' => '1000.00',
                    'credit' => '0.00',
                    'description' => 'Initial deposit',
                    'date' => '2025-10-30',
                ],
                [
                    'id' => 'je-002',
                    'debit' => '0.00',
                    'credit' => '500.00',
                    'description' => 'Withdrawal',
                    'date' => '2025-10-29',
                ],
            ],
            'total' => 2,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/gl-accounts/1000/transactions', [])
            ->willReturn($mockResponse);

        $result = $this->accounts->getTransactions('1000');

        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']);
        $this->assertEquals('je-001', $result['data'][0]['id']);
    }

    public function testGetTransactionsWithFilters(): void
    {
        $filters = ['startDate' => '2025-10-01', 'endDate' => '2025-10-31'];
        $mockResponse = [
            'data' => [
                [
                    'id' => 'je-001',
                    'debit' => '1000.00',
                    'credit' => '0.00',
                    'description' => 'Initial deposit',
                    'date' => '2025-10-30',
                ],
            ],
            'total' => 1,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/gl-accounts/1000/transactions', $filters)
            ->willReturn($mockResponse);

        $result = $this->accounts->getTransactions('1000', $filters);

        $this->assertCount(1, $result['data']);
    }

    // ========================================
    // Integration tests
    // ========================================

    public function testMultipleOperationsSequence(): void
    {
        // Create
        $createResponse = [
            'id' => '9999',
            'code' => '9999',
            'name' => 'Test Account',
            'status' => 'active',
        ];

        // Get
        $getResponse = [
            'id' => '9999',
            'code' => '9999',
            'name' => 'Test Account',
            'status' => 'active',
            'balance' => '5000.00',
        ];

        // Update
        $updateResponse = [
            'id' => '9999',
            'code' => '9999',
            'name' => 'Updated Test Account',
            'status' => 'active',
        ];

        $this->httpClientMock
            ->method('post')
            ->willReturn($createResponse);

        $this->httpClientMock
            ->method('get')
            ->willReturn($getResponse);

        $this->httpClientMock
            ->method('patch')
            ->willReturn($updateResponse);

        $created = $this->accounts->create(['name' => 'Test Account']);
        $this->assertEquals('9999', $created['id']);

        $retrieved = $this->accounts->get('9999');
        $this->assertEquals('9999', $retrieved['id']);

        $updated = $this->accounts->update('9999', ['name' => 'Updated Test Account']);
        $this->assertEquals('Updated Test Account', $updated['name']);
    }

    public function testGetBalanceAndTransactionsSequence(): void
    {
        $balanceResponse = [
            'accountId' => '1000',
            'balance' => '100000.00',
            'currency' => 'USD',
        ];

        $transactionsResponse = [
            'data' => [
                [
                    'id' => 'je-001',
                    'debit' => '10000.00',
                    'credit' => '0.00',
                    'date' => '2025-10-30',
                ],
            ],
            'total' => 1,
        ];

        $this->httpClientMock
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls($balanceResponse, $transactionsResponse);

        $balance = $this->accounts->getBalance('1000');
        $this->assertEquals('100000.00', $balance['balance']);

        $transactions = $this->accounts->getTransactions('1000');
        $this->assertCount(1, $transactions['data']);
    }
}
