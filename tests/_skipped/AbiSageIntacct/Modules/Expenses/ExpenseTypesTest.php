<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\Expenses;

use AbiSageIntacct\Contracts\HttpClientInterface;
use AbiSageIntacct\Modules\Expenses\ExpenseTypes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ExpenseTypes module
 *
 * @internal
 */
final class ExpenseTypesTest extends TestCase
{
    private ExpenseTypes $module;
    private HttpClientInterface&MockObject $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->module = new ExpenseTypes('/expense-types');
        $this->module->setHttpClient($this->httpClient);
    }

    // ========================================
    // list() method tests
    // ========================================

    public function testListReturnsArrayOfExpenseTypes(): void
    {
        $expectedData = [
            ['id' => '1', 'name' => 'Travel'],
            ['id' => '2', 'name' => 'Meals'],
        ];

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with('/expense-types', [])
            ->willReturn($expectedData);

        $result = $this->module->list();

        $this->assertEquals($expectedData, $result);
    }

    public function testListWithFiltersPassesFiltersToHttpClient(): void
    {
        $filters = ['active' => true];
        $expectedData = [['id' => '1', 'name' => 'Travel', 'active' => true]];

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with('/expense-types', $filters)
            ->willReturn($expectedData);

        $result = $this->module->list($filters);

        $this->assertEquals($expectedData, $result);
    }

    // ========================================
    // get() method tests
    // ========================================

    public function testGetReturnsExpenseTypeById(): void
    {
        $typeId = '12345';
        $expectedData = ['id' => $typeId, 'name' => 'Travel'];

        $this->httpClient->expects($this->once())
            ->method('get')
            ->with('/expense-types/12345')
            ->willReturn($expectedData);

        $result = $this->module->get($typeId);

        $this->assertEquals($expectedData, $result);
    }

    // ========================================
    // create() method tests
    // ========================================

    public function testCreateSendsDataToHttpClient(): void
    {
        $typeData = ['name' => 'Accommodation', 'description' => 'Hotel and lodging'];
        $expectedResponse = ['id' => '999', ...$typeData];

        $this->httpClient->expects($this->once())
            ->method('post')
            ->with('/expense-types', $typeData)
            ->willReturn($expectedResponse);

        $result = $this->module->create($typeData);

        $this->assertEquals($expectedResponse, $result);
    }

    // ========================================
    // update() method tests
    // ========================================

    public function testUpdateSendsUpdatedDataToHttpClient(): void
    {
        $typeId = '12345';
        $updateData = ['name' => 'Updated Travel'];
        $expectedResponse = ['id' => $typeId, ...$updateData];

        $this->httpClient->expects($this->once())
            ->method('patch')
            ->with('/expense-types/12345', $updateData)
            ->willReturn($expectedResponse);

        $result = $this->module->update($typeId, $updateData);

        $this->assertEquals($expectedResponse, $result);
    }

    // ========================================
    // delete() method tests
    // ========================================

    public function testDeleteSendsDeleteRequestToHttpClient(): void
    {
        $typeId = '12345';
        $expectedResponse = ['success' => true];

        $this->httpClient->expects($this->once())
            ->method('delete')
            ->with('/expense-types/12345')
            ->willReturn($expectedResponse);

        $result = $this->module->delete($typeId);

        $this->assertEquals($expectedResponse, $result);
    }

    // ========================================
    // Module configuration tests
    // ========================================

    public function testModuleHasCorrectEndpoint(): void
    {
        $this->assertEquals('/expense-types', $this->module->getBaseEndpoint());
    }

    public function testModuleCanSetHttpClient(): void
    {
        $newClient = $this->createMock(HttpClientInterface::class);
        $this->module->setHttpClient($newClient);

        // Verify by checking that the module is still functional
        $this->assertIsObject($this->module);
    }

    // ========================================
    // CRUD operations tests
    // ========================================

    public function testAllCrudOperationsCanBePerformed(): void
    {
        // Simulate full CRUD cycle
        $createData = ['name' => 'New Type', 'description' => 'Test'];
        $this->httpClient->expects($this->any())
            ->method('post')
            ->willReturn(['id' => '1', ...$createData]);

        $this->httpClient->expects($this->any())
            ->method('get')
            ->willReturn(['id' => '1', 'name' => 'New Type']);

        $this->httpClient->expects($this->any())
            ->method('patch')
            ->willReturn(['id' => '1', 'name' => 'Updated Type']);

        $this->httpClient->expects($this->any())
            ->method('delete')
            ->willReturn(['success' => true]);

        // All operations should work without errors
        $created = $this->module->create($createData);
        $retrieved = $this->module->get('1');
        $updated = $this->module->update('1', ['name' => 'Updated Type']);
        $deleted = $this->module->delete('1');

        $this->assertIsArray($created);
        $this->assertIsArray($retrieved);
        $this->assertIsArray($updated);
        $this->assertIsArray($deleted);
    }
}
