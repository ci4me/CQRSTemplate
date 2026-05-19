<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\Common;

use AbiSageIntacct\Contracts\HttpClientInterface;
use AbiSageIntacct\Modules\Common\BulkRequests;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class BulkRequestsTest extends TestCase
{
    private BulkRequests $bulkRequests;
    private HttpClientInterface $httpClientMock;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->bulkRequests = new BulkRequests('/bulk-requests');
        $this->bulkRequests->setHttpClient($this->httpClientMock);
    }

    // ========================================
    // list() - List all bulk requests
    // ========================================

    public function testListReturnsAllBulkRequests(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => 'req-001',
                    'status' => 'completed',
                    'createdAt' => '2025-10-30T10:00:00Z',
                    'totalRecords' => 100,
                ],
                [
                    'id' => 'req-002',
                    'status' => 'processing',
                    'createdAt' => '2025-10-30T11:00:00Z',
                    'totalRecords' => 50,
                ],
            ],
            'total' => 2,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/bulk-requests', [])
            ->willReturn($mockResponse);

        $result = $this->bulkRequests->list();

        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']);
    }

    public function testListWithQueryParams(): void
    {
        $params = ['status' => 'completed', 'limit' => 10];
        $mockResponse = [
            'data' => [
                [
                    'id' => 'req-001',
                    'status' => 'completed',
                    'createdAt' => '2025-10-30T10:00:00Z',
                    'totalRecords' => 100,
                ],
            ],
            'total' => 1,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/bulk-requests', $params)
            ->willReturn($mockResponse);

        $result = $this->bulkRequests->list($params);

        $this->assertCount(1, $result['data']);
    }

    // ========================================
    // get() - Get single bulk request
    // ========================================

    public function testGetReturnsBulkRequestById(): void
    {
        $mockResponse = [
            'id' => 'req-001',
            'status' => 'completed',
            'createdAt' => '2025-10-30T10:00:00Z',
            'completedAt' => '2025-10-30T10:15:00Z',
            'totalRecords' => 100,
            'successfulRecords' => 98,
            'failedRecords' => 2,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/bulk-requests/req-001')
            ->willReturn($mockResponse);

        $result = $this->bulkRequests->get('req-001');

        $this->assertIsArray($result);
        $this->assertEquals('req-001', $result['id']);
        $this->assertEquals('completed', $result['status']);
    }

    // ========================================
    // create() - Create new bulk request
    // ========================================

    public function testCreateNewBulkRequest(): void
    {
        $requestData = [
            'operation' => 'create',
            'objectType' => 'AR_CUSTOMER',
            'records' => [
                ['name' => 'Customer 1', 'email' => 'customer1@example.com'],
                ['name' => 'Customer 2', 'email' => 'customer2@example.com'],
            ],
        ];

        $mockResponse = [
            'id' => 'req-003',
            'status' => 'queued',
            'createdAt' => '2025-10-30T12:00:00Z',
            'totalRecords' => 2,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('post')
            ->with('/bulk-requests', $requestData)
            ->willReturn($mockResponse);

        $result = $this->bulkRequests->create($requestData);

        $this->assertIsArray($result);
        $this->assertEquals('req-003', $result['id']);
        $this->assertEquals('queued', $result['status']);
    }

    // ========================================
    // getStatus() - Get bulk request status
    // ========================================

    public function testGetStatusReturnsBulkRequestStatus(): void
    {
        $mockResponse = [
            'id' => 'req-001',
            'status' => 'processing',
            'createdAt' => '2025-10-30T10:00:00Z',
            'totalRecords' => 100,
            'processedRecords' => 45,
            'successfulRecords' => 43,
            'failedRecords' => 2,
            'progressPercentage' => 45,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/bulk-requests/req-001/status')
            ->willReturn($mockResponse);

        $result = $this->bulkRequests->getStatus('req-001');

        $this->assertIsArray($result);
        $this->assertEquals('req-001', $result['id']);
        $this->assertEquals('processing', $result['status']);
        $this->assertEquals(45, $result['progressPercentage']);
    }

    // ========================================
    // getResults() - Get bulk request results
    // ========================================

    public function testGetResultsReturnsBulkRequestResults(): void
    {
        $mockResponse = [
            'id' => 'req-001',
            'status' => 'completed',
            'results' => [
                [
                    'recordIndex' => 0,
                    'status' => 'success',
                    'createdId' => 'cust-001',
                ],
                [
                    'recordIndex' => 1,
                    'status' => 'success',
                    'createdId' => 'cust-002',
                ],
                [
                    'recordIndex' => 2,
                    'status' => 'error',
                    'error' => 'Invalid email format',
                ],
            ],
            'totalRecords' => 3,
            'successfulRecords' => 2,
            'failedRecords' => 1,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/bulk-requests/req-001/results')
            ->willReturn($mockResponse);

        $result = $this->bulkRequests->getResults('req-001');

        $this->assertIsArray($result);
        $this->assertEquals('req-001', $result['id']);
        $this->assertCount(3, $result['results']);
        $this->assertEquals(2, $result['successfulRecords']);
        $this->assertEquals(1, $result['failedRecords']);
    }

    // ========================================
    // Integration tests
    // ========================================

    public function testBulkRequestLifecycle(): void
    {
        $createResponse = [
            'id' => 'req-new',
            'status' => 'queued',
            'createdAt' => '2025-10-30T13:00:00Z',
        ];

        $statusResponse = [
            'id' => 'req-new',
            'status' => 'processing',
            'progressPercentage' => 50,
        ];

        $resultsResponse = [
            'id' => 'req-new',
            'status' => 'completed',
            'successfulRecords' => 100,
            'failedRecords' => 0,
        ];

        $this->httpClientMock
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls($statusResponse, $resultsResponse);

        $this->httpClientMock
            ->expects($this->once())
            ->method('post')
            ->willReturn($createResponse);

        // Create request
        $created = $this->bulkRequests->create(['records' => []]);
        $this->assertEquals('req-new', $created['id']);

        // Check status
        $status = $this->bulkRequests->getStatus('req-new');
        $this->assertEquals('processing', $status['status']);

        // Get results
        $results = $this->bulkRequests->getResults('req-new');
        $this->assertEquals('completed', $results['status']);
    }
}
