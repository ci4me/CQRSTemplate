<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\CashManagement;

use AbiSageIntacct\Contracts\HttpClientInterface;
use AbiSageIntacct\Modules\CashManagement\Transactions;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TransactionsTest extends TestCase
{
    private Transactions $transactions;

    protected function setUp(): void
    {
        $this->transactions = new Transactions('/cm-transactions');
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
    // list() - List all transactions
    // ========================================

    public function testListReturnsAllTransactions(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => 'txn-001',
                    'accountId' => '1',
                    'amount' => '1500.00',
                    'type' => 'deposit',
                    'date' => '2025-10-30',
                    'status' => 'completed',
                ],
                [
                    'id' => 'txn-002',
                    'accountId' => '1',
                    'amount' => '200.00',
                    'type' => 'withdrawal',
                    'date' => '2025-10-29',
                    'status' => 'completed',
                ],
            ],
            'total' => 2,
        ];

        $stub = $this->createHttpClientStub(['get' => $mockResponse]);
        $this->transactions->setHttpClient($stub);

        $result = $this->transactions->list();

        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']);
    }

    public function testListWithFilters(): void
    {
        $filters = ['status' => 'completed', 'type' => 'deposit'];
        $mockResponse = [
            'data' => [
                [
                    'id' => 'txn-001',
                    'accountId' => '1',
                    'amount' => '1500.00',
                    'type' => 'deposit',
                    'date' => '2025-10-30',
                    'status' => 'completed',
                ],
            ],
            'total' => 1,
        ];

        $stub = $this->createHttpClientStub(['get' => $mockResponse]);
        $this->transactions->setHttpClient($stub);

        $result = $this->transactions->list($filters);

        $this->assertCount(1, $result['data']);
        $this->assertEquals('deposit', $result['data'][0]['type']);
    }

    public function testListWithDateRangeFilter(): void
    {
        $filters = [
            'startDate' => '2025-10-01',
            'endDate' => '2025-10-31',
        ];

        $mockResponse = [
            'data' => [
                [
                    'id' => 'txn-001',
                    'date' => '2025-10-30',
                ],
            ],
            'total' => 1,
        ];

        $stub = $this->createHttpClientStub(['get' => $mockResponse]);
        $this->transactions->setHttpClient($stub);

        $result = $this->transactions->list($filters);

        $this->assertCount(1, $result['data']);
    }

    // ========================================
    // get() - Get single transaction
    // ========================================

    public function testGetReturnsTransactionById(): void
    {
        $mockResponse = [
            'id' => 'txn-001',
            'accountId' => '1',
            'amount' => '1500.00',
            'type' => 'deposit',
            'date' => '2025-10-30',
            'status' => 'completed',
            'description' => 'Salary deposit',
            'reference' => 'REF-001',
        ];

        $stub = $this->createHttpClientStub(['get' => $mockResponse]);
        $this->transactions->setHttpClient($stub);

        $result = $this->transactions->get('txn-001');

        $this->assertIsArray($result);
        $this->assertEquals('txn-001', $result['id']);
        $this->assertEquals('1500.00', $result['amount']);
        $this->assertEquals('deposit', $result['type']);
    }

    public function testGetHandlesNonexistentTransaction(): void
    {
        $mockResponse = [
            'error' => 'Transaction not found',
            'code' => 404,
        ];

        $stub = $this->createHttpClientStub(['get' => $mockResponse]);
        $this->transactions->setHttpClient($stub);

        $result = $this->transactions->get('invalid-id');

        $this->assertArrayHasKey('error', $result);
    }

    // ========================================
    // create() - Create new transaction
    // ========================================

    public function testCreateNewTransaction(): void
    {
        $transactionData = [
            'accountId' => '1',
            'amount' => '2000.00',
            'type' => 'deposit',
            'date' => '2025-10-30',
            'description' => 'Customer payment',
            'reference' => 'INV-12345',
        ];

        $mockResponse = [
            'id' => 'txn-003',
            'accountId' => '1',
            'amount' => '2000.00',
            'type' => 'deposit',
            'date' => '2025-10-30',
            'description' => 'Customer payment',
            'reference' => 'INV-12345',
            'status' => 'pending',
        ];

        $stub = $this->createHttpClientStub(['post' => $mockResponse]);
        $this->transactions->setHttpClient($stub);

        $result = $this->transactions->create($transactionData);

        $this->assertIsArray($result);
        $this->assertEquals('txn-003', $result['id']);
        $this->assertEquals('2000.00', $result['amount']);
        $this->assertEquals('pending', $result['status']);
    }

    public function testCreateDepositTransaction(): void
    {
        $transactionData = [
            'accountId' => '1',
            'amount' => '5000.00',
            'type' => 'deposit',
            'date' => '2025-10-30',
            'description' => 'Bank transfer in',
        ];

        $mockResponse = [
            'id' => 'txn-004',
            'status' => 'completed',
        ];

        $stub = $this->createHttpClientStub(['post' => $mockResponse]);
        $this->transactions->setHttpClient($stub);

        $result = $this->transactions->create($transactionData);

        $this->assertEquals('txn-004', $result['id']);
    }

    public function testCreateWithdrawalTransaction(): void
    {
        $transactionData = [
            'accountId' => '1',
            'amount' => '500.00',
            'type' => 'withdrawal',
            'date' => '2025-10-30',
            'description' => 'Check payment',
        ];

        $mockResponse = [
            'id' => 'txn-005',
            'status' => 'completed',
        ];

        $stub = $this->createHttpClientStub(['post' => $mockResponse]);
        $this->transactions->setHttpClient($stub);

        $result = $this->transactions->create($transactionData);

        $this->assertEquals('txn-005', $result['id']);
    }

    // ========================================
    // update() - Update transaction
    // ========================================

    public function testUpdateTransaction(): void
    {
        $transactionData = [
            'description' => 'Updated description',
            'reference' => 'NEW-REF-001',
        ];

        $mockResponse = [
            'id' => 'txn-001',
            'description' => 'Updated description',
            'reference' => 'NEW-REF-001',
            'status' => 'completed',
        ];

        $stub = $this->createHttpClientStub(['patch' => $mockResponse]);
        $this->transactions->setHttpClient($stub);

        $result = $this->transactions->update('txn-001', $transactionData);

        $this->assertIsArray($result);
        $this->assertEquals('Updated description', $result['description']);
        $this->assertEquals('NEW-REF-001', $result['reference']);
    }

    public function testUpdateTransactionPartialFields(): void
    {
        $transactionData = [
            'description' => 'Corrected entry',
        ];

        $mockResponse = [
            'id' => 'txn-002',
            'description' => 'Corrected entry',
        ];

        $stub = $this->createHttpClientStub(['patch' => $mockResponse]);
        $this->transactions->setHttpClient($stub);

        $result = $this->transactions->update('txn-002', $transactionData);

        $this->assertEquals('Corrected entry', $result['description']);
    }

    // ========================================
    // delete() - Delete transaction
    // ========================================

    public function testDeleteTransaction(): void
    {
        $mockResponse = [
            'id' => 'txn-001',
            'status' => 'deleted',
        ];

        $stub = $this->createHttpClientStub(['delete' => $mockResponse]);
        $this->transactions->setHttpClient($stub);

        $result = $this->transactions->delete('txn-001');

        $this->assertIsArray($result);
        $this->assertEquals('deleted', $result['status']);
    }

    // ========================================
    // Integration tests
    // ========================================

    public function testCompleteTransactionLifecycle(): void
    {
        // Create
        $createResponse = [
            'id' => 'txn-new',
            'status' => 'pending',
        ];

        // Get
        $getResponse = [
            'id' => 'txn-new',
            'status' => 'pending',
            'amount' => '1000.00',
        ];

        // Update
        $updateResponse = [
            'id' => 'txn-new',
            'status' => 'completed',
            'amount' => '1000.00',
        ];

        // Delete
        $deleteResponse = [
            'id' => 'txn-new',
            'status' => 'deleted',
        ];

        $stub = $this->createHttpClientStub([
            'post' => $createResponse,
            'get' => $getResponse,
            'patch' => $updateResponse,
            'delete' => $deleteResponse,
        ]);
        $this->transactions->setHttpClient($stub);

        // Create transaction
        $created = $this->transactions->create([
            'accountId' => '1',
            'amount' => '1000.00',
            'type' => 'deposit',
        ]);
        $this->assertEquals('txn-new', $created['id']);

        // Retrieve transaction
        $retrieved = $this->transactions->get('txn-new');
        $this->assertEquals('pending', $retrieved['status']);

        // Update transaction
        $updated = $this->transactions->update('txn-new', ['status' => 'completed']);
        $this->assertEquals('completed', $updated['status']);

        // Delete transaction
        $deleted = $this->transactions->delete('txn-new');
        $this->assertEquals('deleted', $deleted['status']);
    }

    public function testBulkTransactionOperations(): void
    {
        $mockResponse = [
            'data' => [
                ['id' => 'txn-001', 'status' => 'updated'],
                ['id' => 'txn-002', 'status' => 'updated'],
                ['id' => 'txn-003', 'status' => 'updated'],
            ],
            'total' => 3,
        ];

        $stub = $this->createHttpClientStub(['get' => $mockResponse]);
        $this->transactions->setHttpClient($stub);

        $result = $this->transactions->list(['accountId' => '1']);

        $this->assertCount(3, $result['data']);
    }
}
