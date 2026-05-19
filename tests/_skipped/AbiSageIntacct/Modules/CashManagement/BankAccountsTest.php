<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\CashManagement;

use AbiSageIntacct\Contracts\HttpClientInterface;
use AbiSageIntacct\Modules\CashManagement\BankAccounts;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class BankAccountsTest extends TestCase
{
    private BankAccounts $bankAccounts;

    /**
     * Mock HTTP client stub for testing
     *
     * @var array<string, mixed>
     */
    private array $httpClientStub;

    protected function setUp(): void
    {
        $this->httpClientStub = [];
        $this->bankAccounts = new BankAccounts('/cm-bank-accounts');
    }

    /**
     * Create an anonymous class that acts like CurlClient for testing
     *
     * @param array<string, mixed> $responses
     */
    private function createHttpClientStub(array $responses): HttpClientInterface
    {
        return new class ($responses) implements HttpClientInterface {
            public function __construct(private array $responses)
            {
            }

            public function setAccessToken(string $token): void
            {
                // Stub method
            }

            public function get(string $endpoint, array $queryParams = [], array $headers = []): array
            {
                return $this->responses['get'] ?? ['data' => []];
            }

            public function post(string $endpoint, ?array $data = null, array $headers = []): array
            {
                return $this->responses['post'] ?? ['id' => 'test-id'];
            }

            public function put(string $endpoint, ?array $data = null, array $headers = []): array
            {
                return $this->responses['put'] ?? ['id' => 'test-id'];
            }

            public function patch(string $endpoint, ?array $data = null, array $headers = []): array
            {
                return $this->responses['patch'] ?? ['id' => 'test-id'];
            }

            public function delete(string $endpoint, array $headers = []): array
            {
                return $this->responses['delete'] ?? ['status' => 'deleted'];
            }
        };
    }

    // ========================================
    // list() - List all bank accounts
    // ========================================

    public function testListReturnsAllBankAccounts(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => '1',
                    'name' => 'Primary Checking',
                    'number' => '1234567890',
                    'status' => 'active',
                ],
                [
                    'id' => '2',
                    'name' => 'Savings Account',
                    'number' => '0987654321',
                    'status' => 'active',
                ],
            ],
            'total' => 2,
        ];

        $stub = $this->createHttpClientStub(['get' => $mockResponse]);
        $this->bankAccounts->setHttpClient($stub);

        $result = $this->bankAccounts->list();

        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']);
    }

    public function testListWithFilters(): void
    {
        $filters = ['status' => 'active', 'name' => 'Checking'];
        $mockResponse = [
            'data' => [
                [
                    'id' => '1',
                    'name' => 'Primary Checking',
                    'number' => '1234567890',
                    'status' => 'active',
                ],
            ],
            'total' => 1,
        ];

        $stub = $this->createHttpClientStub(['get' => $mockResponse]);
        $this->bankAccounts->setHttpClient($stub);

        $result = $this->bankAccounts->list($filters);

        $this->assertCount(1, $result['data']);
    }

    // ========================================
    // get() - Get single bank account
    // ========================================

    public function testGetReturnsAccountById(): void
    {
        $mockResponse = [
            'id' => '1',
            'name' => 'Primary Checking',
            'number' => '1234567890',
            'status' => 'active',
            'balance' => '10000.00',
        ];

        $stub = $this->createHttpClientStub(['get' => $mockResponse]);
        $this->bankAccounts->setHttpClient($stub);

        $result = $this->bankAccounts->get('1');

        $this->assertIsArray($result);
        $this->assertEquals('1', $result['id']);
        $this->assertEquals('Primary Checking', $result['name']);
    }

    // ========================================
    // create() - Create new bank account
    // ========================================

    public function testCreateNewBankAccount(): void
    {
        $accountData = [
            'name' => 'New Checking Account',
            'number' => '1111111111',
            'accountType' => 'checking',
            'currency' => 'USD',
        ];

        $mockResponse = [
            'id' => '3',
            'name' => 'New Checking Account',
            'number' => '1111111111',
            'accountType' => 'checking',
            'currency' => 'USD',
            'status' => 'active',
        ];

        $stub = $this->createHttpClientStub(['post' => $mockResponse]);
        $this->bankAccounts->setHttpClient($stub);

        $result = $this->bankAccounts->create($accountData);

        $this->assertIsArray($result);
        $this->assertEquals('3', $result['id']);
        $this->assertEquals('New Checking Account', $result['name']);
    }

    // ========================================
    // update() - Update bank account
    // ========================================

    public function testUpdateBankAccount(): void
    {
        $accountData = [
            'name' => 'Updated Account Name',
        ];

        $mockResponse = [
            'id' => '1',
            'name' => 'Updated Account Name',
            'number' => '1234567890',
            'status' => 'active',
        ];

        $stub = $this->createHttpClientStub(['patch' => $mockResponse]);
        $this->bankAccounts->setHttpClient($stub);

        $result = $this->bankAccounts->update('1', $accountData);

        $this->assertIsArray($result);
        $this->assertEquals('Updated Account Name', $result['name']);
    }

    // ========================================
    // delete() - Delete bank account
    // ========================================

    public function testDeleteBankAccount(): void
    {
        $mockResponse = [
            'id' => '1',
            'status' => 'deleted',
        ];

        $stub = $this->createHttpClientStub(['delete' => $mockResponse]);
        $this->bankAccounts->setHttpClient($stub);

        $result = $this->bankAccounts->delete('1');

        $this->assertIsArray($result);
        $this->assertEquals('deleted', $result['status']);
    }

    // ========================================
    // getTransactions() - Get account transactions
    // ========================================

    public function testGetTransactionsReturnsAccountTransactions(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => 'txn-001',
                    'amount' => '1500.00',
                    'type' => 'deposit',
                    'date' => '2025-10-30',
                ],
                [
                    'id' => 'txn-002',
                    'amount' => '200.00',
                    'type' => 'withdrawal',
                    'date' => '2025-10-29',
                ],
            ],
            'total' => 2,
        ];

        $stub = $this->createHttpClientStub(['get' => $mockResponse]);
        $this->bankAccounts->setHttpClient($stub);

        $result = $this->bankAccounts->getTransactions('1');

        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']);
        $this->assertEquals('txn-001', $result['data'][0]['id']);
    }

    public function testGetTransactionsWithFilters(): void
    {
        $filters = ['startDate' => '2025-10-01', 'endDate' => '2025-10-31'];
        $mockResponse = [
            'data' => [
                [
                    'id' => 'txn-001',
                    'amount' => '1500.00',
                    'type' => 'deposit',
                    'date' => '2025-10-30',
                ],
            ],
            'total' => 1,
        ];

        $stub = $this->createHttpClientStub(['get' => $mockResponse]);
        $this->bankAccounts->setHttpClient($stub);

        $result = $this->bankAccounts->getTransactions('1', $filters);

        $this->assertCount(1, $result['data']);
    }

    // ========================================
    // getBalance() - Get account balance
    // ========================================

    public function testGetBalanceReturnsAccountBalance(): void
    {
        $mockResponse = [
            'accountId' => '1',
            'balance' => '25000.50',
            'currency' => 'USD',
            'lastUpdated' => '2025-10-30T10:30:00Z',
        ];

        $stub = $this->createHttpClientStub(['get' => $mockResponse]);
        $this->bankAccounts->setHttpClient($stub);

        $result = $this->bankAccounts->getBalance('1');

        $this->assertIsArray($result);
        $this->assertEquals('1', $result['accountId']);
        $this->assertEquals('25000.50', $result['balance']);
        $this->assertEquals('USD', $result['currency']);
    }

    // ========================================
    // Integration tests
    // ========================================

    public function testMultipleOperationsSequence(): void
    {
        // Create
        $createResponse = [
            'id' => '99',
            'name' => 'Test Account',
            'status' => 'active',
        ];

        // Get
        $getResponse = [
            'id' => '99',
            'name' => 'Test Account',
            'status' => 'active',
            'balance' => '5000.00',
        ];

        // Update
        $updateResponse = [
            'id' => '99',
            'name' => 'Updated Test Account',
            'status' => 'active',
        ];

        $stub = $this->createHttpClientStub([
            'post' => $createResponse,
            'get' => $getResponse,
            'patch' => $updateResponse,
        ]);
        $this->bankAccounts->setHttpClient($stub);

        $created = $this->bankAccounts->create(['name' => 'Test Account']);
        $this->assertEquals('99', $created['id']);

        $retrieved = $this->bankAccounts->get('99');
        $this->assertEquals('99', $retrieved['id']);

        $updated = $this->bankAccounts->update('99', ['name' => 'Updated Test Account']);
        $this->assertEquals('Updated Test Account', $updated['name']);
    }
}
