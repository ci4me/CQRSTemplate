<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\FixedAssets;

use AbiSageIntacct\Contracts\HttpClientInterface;
use AbiSageIntacct\Modules\FixedAssets\AssetTypes;
use PHPUnit\Framework\TestCase;

/**
 * Mock HTTP Client for testing
 */
final class MockHttpClient2 implements HttpClientInterface
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
final class AssetTypesTest extends TestCase
{
    private AssetTypes $assetTypes;
    private MockHttpClient2 $httpClientMock;

    protected function setUp(): void
    {
        $this->httpClientMock = new MockHttpClient2();
        $this->assetTypes = new AssetTypes('/fixed-asset-types');
        // Use reflection to set the httpClient
        $reflection = new \ReflectionClass($this->assetTypes);
        $property = $reflection->getProperty('httpClient');
        $property->setAccessible(true);
        $property->setValue($this->assetTypes, $this->httpClientMock);
    }

    // ========================================
    // list() - List all asset types
    // ========================================

    public function testListReturnsAllAssetTypes(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => 'TYPE-001',
                    'name' => 'Furniture',
                    'description' => 'Office furniture and fixtures',
                    'depreciationMethod' => 'straight-line',
                    'usefulLife' => 60,
                    'status' => 'active',
                ],
                [
                    'id' => 'TYPE-002',
                    'name' => 'Vehicles',
                    'description' => 'Company vehicles',
                    'depreciationMethod' => 'straight-line',
                    'usefulLife' => 120,
                    'status' => 'active',
                ],
                [
                    'id' => 'TYPE-003',
                    'name' => 'Equipment',
                    'description' => 'Office and computer equipment',
                    'depreciationMethod' => 'straight-line',
                    'usefulLife' => 36,
                    'status' => 'active',
                ],
            ],
            'total' => 3,
        ];

        $this->httpClientMock->mockGet('/fixed-asset-types', $mockResponse);

        $result = $this->assetTypes->list();

        $this->assertIsArray($result);
        $this->assertCount(3, $result['data']);
    }

    public function testListWithFilters(): void
    {
        $filters = ['status' => 'active', 'depreciationMethod' => 'straight-line'];
        $mockResponse = [
            'data' => [
                [
                    'id' => 'TYPE-001',
                    'name' => 'Furniture',
                    'depreciationMethod' => 'straight-line',
                    'status' => 'active',
                ],
            ],
            'total' => 1,
        ];

        $this->httpClientMock->mockGet('/fixed-asset-types', $mockResponse);

        $result = $this->assetTypes->list($filters);

        $this->assertCount(1, $result['data']);
    }

    // ========================================
    // get() - Get single asset type
    // ========================================

    public function testGetReturnsAssetTypeById(): void
    {
        $mockResponse = [
            'id' => 'TYPE-001',
            'name' => 'Furniture',
            'description' => 'Office furniture and fixtures',
            'depreciationMethod' => 'straight-line',
            'usefulLife' => 60,
            'status' => 'active',
            'createdDate' => '2024-01-01',
            'modifiedDate' => '2024-10-01',
        ];

        $this->httpClientMock->mockGet('/fixed-asset-types/TYPE-001', $mockResponse);

        $result = $this->assetTypes->get('TYPE-001');

        $this->assertIsArray($result);
        $this->assertEquals('TYPE-001', $result['id']);
        $this->assertEquals('Furniture', $result['name']);
        $this->assertEquals('straight-line', $result['depreciationMethod']);
    }

    // ========================================
    // create() - Create new asset type
    // ========================================

    public function testCreateNewAssetType(): void
    {
        $assetTypeData = [
            'name' => 'Building',
            'description' => 'Company buildings and real estate',
            'depreciationMethod' => 'straight-line',
            'usefulLife' => 300,
        ];

        $mockResponse = [
            'id' => 'TYPE-004',
            'name' => 'Building',
            'description' => 'Company buildings and real estate',
            'depreciationMethod' => 'straight-line',
            'usefulLife' => 300,
            'status' => 'active',
        ];

        $this->httpClientMock->mockGet('/fixed-asset-types', $mockResponse);

        $result = $this->assetTypes->create($assetTypeData);

        $this->assertIsArray($result);
        $this->assertEquals('TYPE-004', $result['id']);
        $this->assertEquals('Building', $result['name']);
        $this->assertEquals(300, $result['usefulLife']);
    }

    // ========================================
    // update() - Update asset type
    // ========================================

    public function testUpdateAssetType(): void
    {
        $assetTypeData = [
            'description' => 'Updated description for furniture',
            'usefulLife' => 72,
        ];

        $mockResponse = [
            'id' => 'TYPE-001',
            'name' => 'Furniture',
            'description' => 'Updated description for furniture',
            'depreciationMethod' => 'straight-line',
            'usefulLife' => 72,
            'status' => 'active',
        ];

        $this->httpClientMock->mockGet('/fixed-asset-types/TYPE-001', $mockResponse);

        $result = $this->assetTypes->update('TYPE-001', $assetTypeData);

        $this->assertIsArray($result);
        $this->assertEquals(72, $result['usefulLife']);
        $this->assertEquals('Updated description for furniture', $result['description']);
    }

    // ========================================
    // delete() - Delete asset type
    // ========================================

    public function testDeleteAssetType(): void
    {
        $mockResponse = [
            'id' => 'TYPE-004',
            'status' => 'deleted',
        ];

        $this->httpClientMock->mockGet('/fixed-asset-types/TYPE-004', $mockResponse);

        $result = $this->assetTypes->delete('TYPE-004');

        $this->assertIsArray($result);
        $this->assertEquals('deleted', $result['status']);
    }

    // ========================================
    // Integration tests
    // ========================================

    public function testMultipleOperationsSequence(): void
    {
        $createResponse = [
            'id' => 'TYPE-099',
            'name' => 'Test Type',
            'depreciationMethod' => 'straight-line',
            'usefulLife' => 60,
            'status' => 'active',
        ];

        $getResponse = [
            'id' => 'TYPE-099',
            'name' => 'Test Type',
            'depreciationMethod' => 'straight-line',
            'usefulLife' => 60,
            'status' => 'active',
        ];

        $updateResponse = [
            'id' => 'TYPE-099',
            'name' => 'Updated Test Type',
            'usefulLife' => 72,
            'status' => 'active',
        ];

        $this->httpClientMock->mockGet('/fixed-asset-types', $createResponse);
        $this->httpClientMock->mockGet('/fixed-asset-types/TYPE-099', $getResponse);
        $this->httpClientMock->mockGet('/fixed-asset-types/TYPE-099', $updateResponse);

        $created = $this->assetTypes->create([
            'name' => 'Test Type',
            'depreciationMethod' => 'straight-line',
            'usefulLife' => 60,
        ]);
        $this->assertEquals('TYPE-099', $created['id']);

        $retrieved = $this->assetTypes->get('TYPE-099');
        $this->assertEquals('TYPE-099', $retrieved['id']);

        $updated = $this->assetTypes->update('TYPE-099', [
            'name' => 'Updated Test Type',
            'usefulLife' => 72,
        ]);
        $this->assertEquals('Updated Test Type', $updated['name']);
        $this->assertEquals(72, $updated['usefulLife']);
    }

    public function testListAndRetrieveOperations(): void
    {
        $listResponse = [
            'data' => [
                ['id' => 'TYPE-001', 'name' => 'Furniture'],
                ['id' => 'TYPE-002', 'name' => 'Vehicles'],
            ],
            'total' => 2,
        ];

        $getResponse = [
            'id' => 'TYPE-001',
            'name' => 'Furniture',
            'description' => 'Office furniture',
            'usefulLife' => 60,
        ];

        $this->httpClientMock->mockGet('/fixed-asset-types', $listResponse);
        $this->httpClientMock->mockGet('/fixed-asset-types/TYPE-001', $getResponse);

        $assets = $this->assetTypes->list();
        $this->assertCount(2, $assets['data']);

        $specific = $this->assetTypes->get('TYPE-001');
        $this->assertEquals('Furniture', $specific['name']);
    }
}
