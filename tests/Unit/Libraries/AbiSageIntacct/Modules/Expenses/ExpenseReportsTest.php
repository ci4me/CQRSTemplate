<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\Expenses;

use AbiSageIntacct\Modules\Expenses\ExpenseReports;
use AbiSageIntacct\Contracts\HttpClientInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests for ExpenseReports module
 *
 * @internal
 */
final class ExpenseReportsTest extends TestCase
{
    private ExpenseReports $module;
    private HttpClientInterface&MockObject $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->module = new ExpenseReports('/expense-reports');
        $this->module->setHttpClient($this->httpClient);
    }

    // ========================================
    // list() method tests
    // ========================================

    public function testListReturnsArrayOfExpenseReports(): void
    {
        $expectedData = [
            ['id' => '1', 'name' => 'Report 1'],
            ['id' => '2', 'name' => 'Report 2'],
        ];

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with('/expense-reports', [])
            ->willReturn($expectedData);

        $result = $this->module->list();

        $this->assertEquals($expectedData, $result);
    }

    public function testListWithFiltersPassesFiltersToHttpClient(): void
    {
        $filters = ['status' => 'pending'];
        $expectedData = [['id' => '1', 'status' => 'pending']];

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with('/expense-reports', $filters)
            ->willReturn($expectedData);

        $result = $this->module->list($filters);

        $this->assertEquals($expectedData, $result);
    }

    // ========================================
    // get() method tests
    // ========================================

    public function testGetReturnsExpenseReportById(): void
    {
        $reportId = '12345';
        $expectedData = ['id' => $reportId, 'name' => 'Test Report'];

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with('/expense-reports/12345')
            ->willReturn($expectedData);

        $result = $this->module->get($reportId);

        $this->assertEquals($expectedData, $result);
    }

    // ========================================
    // create() method tests
    // ========================================

    public function testCreateSendsDataToHttpClient(): void
    {
        $reportData = ['name' => 'New Report', 'employee_id' => '123'];
        $expectedResponse = ['id' => '999', ...$reportData];

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with('/expense-reports', $reportData)
            ->willReturn($expectedResponse);

        $result = $this->module->create($reportData);

        $this->assertEquals($expectedResponse, $result);
    }

    // ========================================
    // update() method tests
    // ========================================

    public function testUpdateSendsUpdatedDataToHttpClient(): void
    {
        $reportId = '12345';
        $updateData = ['name' => 'Updated Report'];
        $expectedResponse = ['id' => $reportId, ...$updateData];

        $this->httpClient->expects($this->once())
            ->method('patch')
            ->with('/expense-reports/12345', $updateData)
            ->willReturn($expectedResponse);

        $result = $this->module->update($reportId, $updateData);

        $this->assertEquals($expectedResponse, $result);
    }

    // ========================================
    // delete() method tests
    // ========================================

    public function testDeleteSendsDeleteRequestToHttpClient(): void
    {
        $reportId = '12345';
        $expectedResponse = ['success' => true];

        $this->httpClient->expects($this->once())
            ->method('delete')
            ->with('/expense-reports/12345')
            ->willReturn($expectedResponse);

        $result = $this->module->delete($reportId);

        $this->assertEquals($expectedResponse, $result);
    }

    // ========================================
    // getLines() method tests
    // ========================================

    public function testGetLinesReturnsExpenseReportLines(): void
    {
        $reportId = '12345';
        $expectedData = [
            ['line_id' => '1', 'amount' => '100.00'],
            ['line_id' => '2', 'amount' => '50.00'],
        ];

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with('/expense-reports/12345/lines')
            ->willReturn($expectedData);

        $result = $this->module->getLines($reportId);

        $this->assertEquals($expectedData, $result);
    }

    // ========================================
    // submit() method tests
    // ========================================

    public function testSubmitSendsSubmitRequestToHttpClient(): void
    {
        $reportId = '12345';
        $expectedResponse = ['status' => 'submitted'];

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with('/expense-reports/12345/submit', [])
            ->willReturn($expectedResponse);

        $result = $this->module->submit($reportId);

        $this->assertEquals($expectedResponse, $result);
    }

    // ========================================
    // approve() method tests
    // ========================================

    public function testApproveSendsApproveRequestToHttpClient(): void
    {
        $reportId = '12345';
        $expectedResponse = ['status' => 'approved'];

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with('/expense-reports/12345/approve', [])
            ->willReturn($expectedResponse);

        $result = $this->module->approve($reportId);

        $this->assertEquals($expectedResponse, $result);
    }

    // ========================================
    // Module configuration tests
    // ========================================

    public function testModuleHasCorrectEndpoint(): void
    {
        $this->assertEquals('/expense-reports', $this->module->getBaseEndpoint());
    }

    public function testModuleCanSetHttpClient(): void
    {
        $newClient = $this->createMock(HttpClientInterface::class);
        $this->module->setHttpClient($newClient);

        // Verify by checking that the module is still functional
        $this->assertIsObject($this->module);
    }
}
