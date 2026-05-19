<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\FixedAssets;

use AbiSageIntacct\Contracts\HttpClientInterface;
use AbiSageIntacct\Modules\FixedAssets\Assets;
use PHPUnit\Framework\TestCase;

/**
 * Mock HTTP Client for testing
 */
final class MockHttpClient implements HttpClientInterface
{
    /** @var array<string, mixed> */
    private array $responses = [];

    /**
     * @param array<string, mixed> $response
     */
    public function mockGet(string $endpoint, array $response): void
    {
        $this->responses[$endpoint] = $response;
    }

    public function setAccessToken(string $token): void
    {
        // Mock implementation
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function get(string $endpoint, array $queryParams = [], array $headers = []): array
    {
        return $this->responses[$endpoint] ?? [];
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function post(string $endpoint, ?array $data = null, array $headers = []): array
    {
        return $this->responses[$endpoint] ?? [];
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function put(string $endpoint, ?array $data = null, array $headers = []): array
    {
        return $this->responses[$endpoint] ?? [];
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function patch(string $endpoint, ?array $data = null, array $headers = []): array
    {
        return $this->responses[$endpoint] ?? [];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function delete(string $endpoint, array $headers = []): array
    {
        return $this->responses[$endpoint] ?? [];
    }
}

/**
 * @internal
 */
final class AssetsTest extends TestCase
{
    private Assets $assets;
    private MockHttpClient $httpClientMock;

    protected function setUp(): void
    {
        $this->httpClientMock = new MockHttpClient();
        $this->assets = new Assets('/fixed-assets');
        // Use reflection to set the httpClient
        $reflection = new \ReflectionClass($this->assets);
        $property = $reflection->getProperty('httpClient');
        $property->setAccessible(true);
        $property->setValue($this->assets, $this->httpClientMock);
    }

    // ========================================
    // list() - List all assets
    // ========================================

    public function testListReturnsAllAssets(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => 'ASSET-001',
                    'name' => 'Office Equipment',
                    'type' => 'furniture',
                    'status' => 'active',
                    'purchaseDate' => '2024-01-15',
                    'cost' => '5000.00',
                ],
                [
                    'id' => 'ASSET-002',
                    'name' => 'Company Vehicle',
                    'type' => 'vehicle',
                    'status' => 'active',
                    'purchaseDate' => '2023-06-10',
                    'cost' => '45000.00',
                ],
            ],
            'total' => 2,
        ];

        $this->httpClientMock->mockGet('/fixed-assets', $mockResponse);

        $result = $this->assets->list();

        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']);
    }

    public function testListWithFilters(): void
    {
        $filters = ['status' => 'active', 'type' => 'furniture'];
        $mockResponse = [
            'data' => [
                [
                    'id' => 'ASSET-001',
                    'name' => 'Office Equipment',
                    'type' => 'furniture',
                    'status' => 'active',
                ],
            ],
            'total' => 1,
        ];

        $this->httpClientMock->mockGet('/fixed-assets', $mockResponse);

        $result = $this->assets->list($filters);

        $this->assertCount(1, $result['data']);
    }

    // ========================================
    // get() - Get single asset
    // ========================================

    public function testGetReturnsAssetById(): void
    {
        $mockResponse = [
            'id' => 'ASSET-001',
            'name' => 'Office Equipment',
            'type' => 'furniture',
            'status' => 'active',
            'purchaseDate' => '2024-01-15',
            'cost' => '5000.00',
            'depreciation' => '416.67',
            'bookValue' => '4583.33',
        ];

        $this->httpClientMock->mockGet('/fixed-assets/ASSET-001', $mockResponse);

        $result = $this->assets->get('ASSET-001');

        $this->assertIsArray($result);
        $this->assertEquals('ASSET-001', $result['id']);
        $this->assertEquals('Office Equipment', $result['name']);
    }

    // ========================================
    // create() - Create new asset
    // ========================================

    public function testCreateNewAsset(): void
    {
        $assetData = [
            'name' => 'New Computer',
            'type' => 'equipment',
            'purchaseDate' => '2025-10-30',
            'cost' => '2000.00',
            'assetTypeId' => 'TYPE-001',
        ];

        $mockResponse = [
            'id' => 'ASSET-003',
            'name' => 'New Computer',
            'type' => 'equipment',
            'status' => 'active',
            'purchaseDate' => '2025-10-30',
            'cost' => '2000.00',
        ];

        $this->httpClientMock->mockGet('/fixed-assets', $mockResponse);

        $result = $this->assets->create($assetData);

        $this->assertIsArray($result);
        $this->assertEquals('ASSET-003', $result['id']);
        $this->assertEquals('New Computer', $result['name']);
    }

    // ========================================
    // update() - Update asset
    // ========================================

    public function testUpdateAsset(): void
    {
        $assetData = [
            'name' => 'Updated Asset Name',
            'status' => 'inactive',
        ];

        $mockResponse = [
            'id' => 'ASSET-001',
            'name' => 'Updated Asset Name',
            'type' => 'furniture',
            'status' => 'inactive',
        ];

        $this->httpClientMock->mockGet('/fixed-assets/ASSET-001', $mockResponse);

        $result = $this->assets->update('ASSET-001', $assetData);

        $this->assertIsArray($result);
        $this->assertEquals('Updated Asset Name', $result['name']);
        $this->assertEquals('inactive', $result['status']);
    }

    // ========================================
    // delete() - Delete asset
    // ========================================

    public function testDeleteAsset(): void
    {
        $mockResponse = [
            'id' => 'ASSET-001',
            'status' => 'deleted',
        ];

        $this->httpClientMock->mockGet('/fixed-assets/ASSET-001', $mockResponse);

        $result = $this->assets->delete('ASSET-001');

        $this->assertIsArray($result);
        $this->assertEquals('deleted', $result['status']);
    }

    // ========================================
    // getDepreciation() - Get asset depreciation
    // ========================================

    public function testGetDepreciationReturnsAssetDepreciation(): void
    {
        $mockResponse = [
            'assetId' => 'ASSET-001',
            'totalCost' => '5000.00',
            'totalDepreciation' => '1250.00',
            'bookValue' => '3750.00',
            'monthlyDepreciation' => '83.33',
            'depreciationMethod' => 'straight-line',
            'usefulLife' => 60,
            'monthsDepreciated' => 15,
        ];

        $this->httpClientMock->mockGet('/fixed-assets/ASSET-001/depreciation', $mockResponse);

        $result = $this->assets->getDepreciation('ASSET-001');

        $this->assertIsArray($result);
        $this->assertEquals('ASSET-001', $result['assetId']);
        $this->assertEquals('5000.00', $result['totalCost']);
        $this->assertEquals('1250.00', $result['totalDepreciation']);
        $this->assertEquals('straight-line', $result['depreciationMethod']);
    }

    // ========================================
    // getTransactions() - Get asset transactions
    // ========================================

    public function testGetTransactionsReturnsAssetTransactions(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => 'TXN-001',
                    'type' => 'purchase',
                    'amount' => '5000.00',
                    'date' => '2024-01-15',
                    'description' => 'Initial purchase',
                ],
                [
                    'id' => 'TXN-002',
                    'type' => 'depreciation',
                    'amount' => '83.33',
                    'date' => '2024-02-15',
                    'description' => 'Monthly depreciation',
                ],
                [
                    'id' => 'TXN-003',
                    'type' => 'depreciation',
                    'amount' => '83.33',
                    'date' => '2024-03-15',
                    'description' => 'Monthly depreciation',
                ],
            ],
            'total' => 3,
        ];

        $this->httpClientMock->mockGet('/fixed-assets/ASSET-001/transactions', $mockResponse);

        $result = $this->assets->getTransactions('ASSET-001');

        $this->assertIsArray($result);
        $this->assertCount(3, $result['data']);
        $this->assertEquals('TXN-001', $result['data'][0]['id']);
        $this->assertEquals('purchase', $result['data'][0]['type']);
    }

    // ========================================
    // Integration tests
    // ========================================

    public function testMultipleOperationsSequence(): void
    {
        $createResponse = [
            'id' => 'ASSET-099',
            'name' => 'Test Asset',
            'status' => 'active',
            'cost' => '3000.00',
        ];

        $getResponse = [
            'id' => 'ASSET-099',
            'name' => 'Test Asset',
            'status' => 'active',
            'cost' => '3000.00',
            'bookValue' => '2700.00',
        ];

        $updateResponse = [
            'id' => 'ASSET-099',
            'name' => 'Updated Test Asset',
            'status' => 'active',
        ];

        $this->httpClientMock->mockGet('/fixed-assets', $createResponse);
        $this->httpClientMock->mockGet('/fixed-assets/ASSET-099', $getResponse);
        $this->httpClientMock->mockGet('/fixed-assets/ASSET-099', $updateResponse);

        $created = $this->assets->create(['name' => 'Test Asset', 'cost' => '3000.00']);
        $this->assertEquals('ASSET-099', $created['id']);

        $retrieved = $this->assets->get('ASSET-099');
        $this->assertEquals('ASSET-099', $retrieved['id']);

        $updated = $this->assets->update('ASSET-099', ['name' => 'Updated Test Asset']);
        $this->assertEquals('Updated Test Asset', $updated['name']);
    }

    public function testDepreciationAndTransactionsRetrieval(): void
    {
        $depreciationResponse = [
            'assetId' => 'ASSET-001',
            'totalCost' => '5000.00',
            'bookValue' => '3750.00',
        ];

        $transactionsResponse = [
            'data' => [
                ['id' => 'TXN-001', 'type' => 'purchase'],
                ['id' => 'TXN-002', 'type' => 'depreciation'],
            ],
            'total' => 2,
        ];

        $this->httpClientMock->mockGet('/fixed-assets/ASSET-001/depreciation', $depreciationResponse);
        $this->httpClientMock->mockGet('/fixed-assets/ASSET-001/transactions', $transactionsResponse);

        $depreciation = $this->assets->getDepreciation('ASSET-001');
        $this->assertEquals('3750.00', $depreciation['bookValue']);

        $transactions = $this->assets->getTransactions('ASSET-001');
        $this->assertCount(2, $transactions['data']);
    }
}
