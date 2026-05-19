<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct\Modules\GeneralLedger;

use AbiSageIntacct\Contracts\HttpClientInterface;
use AbiSageIntacct\Modules\GeneralLedger\JournalEntries;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class JournalEntriesTest extends TestCase
{
    private JournalEntries $journalEntries;
    private HttpClientInterface $httpClientMock;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $this->journalEntries = new JournalEntries('/gl-journal-entries');
        $this->journalEntries->setHttpClient($this->httpClientMock);
    }

    // ========================================
    // list() - List all journal entries
    // ========================================

    public function testListReturnsAllJournalEntries(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => '1',
                    'entryNumber' => 'JE-001',
                    'description' => 'Initial deposit',
                    'status' => 'posted',
                    'date' => '2025-10-30',
                ],
                [
                    'id' => '2',
                    'entryNumber' => 'JE-002',
                    'description' => 'Expense entry',
                    'status' => 'draft',
                    'date' => '2025-10-29',
                ],
            ],
            'total' => 2,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/gl-journal-entries', [])
            ->willReturn($mockResponse);

        $result = $this->journalEntries->list();

        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']);
    }

    public function testListWithFilters(): void
    {
        $filters = ['status' => 'draft', 'date' => '2025-10-29'];
        $mockResponse = [
            'data' => [
                [
                    'id' => '2',
                    'entryNumber' => 'JE-002',
                    'description' => 'Expense entry',
                    'status' => 'draft',
                    'date' => '2025-10-29',
                ],
            ],
            'total' => 1,
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/gl-journal-entries', $filters)
            ->willReturn($mockResponse);

        $result = $this->journalEntries->list($filters);

        $this->assertCount(1, $result['data']);
    }

    // ========================================
    // get() - Get single journal entry
    // ========================================

    public function testGetReturnsJournalEntryById(): void
    {
        $mockResponse = [
            'id' => '1',
            'entryNumber' => 'JE-001',
            'description' => 'Initial deposit',
            'status' => 'posted',
            'date' => '2025-10-30',
            'totalDebit' => '5000.00',
            'totalCredit' => '5000.00',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/gl-journal-entries/1')
            ->willReturn($mockResponse);

        $result = $this->journalEntries->get('1');

        $this->assertIsArray($result);
        $this->assertEquals('1', $result['id']);
        $this->assertEquals('JE-001', $result['entryNumber']);
    }

    // ========================================
    // create() - Create new journal entry
    // ========================================

    public function testCreateNewJournalEntry(): void
    {
        $entryData = [
            'description' => 'New expense entry',
            'date' => '2025-10-30',
            'lines' => [
                [
                    'accountId' => '5000',
                    'debit' => '1000.00',
                    'credit' => '0.00',
                    'description' => 'Expense debit',
                ],
                [
                    'accountId' => '1000',
                    'debit' => '0.00',
                    'credit' => '1000.00',
                    'description' => 'Cash credit',
                ],
            ],
        ];

        $mockResponse = [
            'id' => '3',
            'entryNumber' => 'JE-003',
            'description' => 'New expense entry',
            'status' => 'draft',
            'date' => '2025-10-30',
            'totalDebit' => '1000.00',
            'totalCredit' => '1000.00',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('post')
            ->with('/gl-journal-entries', $entryData)
            ->willReturn($mockResponse);

        $result = $this->journalEntries->create($entryData);

        $this->assertIsArray($result);
        $this->assertEquals('3', $result['id']);
        $this->assertEquals('JE-003', $result['entryNumber']);
    }

    // ========================================
    // update() - Update journal entry
    // ========================================

    public function testUpdateJournalEntry(): void
    {
        $entryData = [
            'description' => 'Updated description',
        ];

        $mockResponse = [
            'id' => '1',
            'entryNumber' => 'JE-001',
            'description' => 'Updated description',
            'status' => 'draft',
            'date' => '2025-10-30',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('patch')
            ->with('/gl-journal-entries/1', $entryData)
            ->willReturn($mockResponse);

        $result = $this->journalEntries->update('1', $entryData);

        $this->assertIsArray($result);
        $this->assertEquals('Updated description', $result['description']);
    }

    // ========================================
    // delete() - Delete journal entry
    // ========================================

    public function testDeleteJournalEntry(): void
    {
        $mockResponse = [
            'id' => '1',
            'status' => 'deleted',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('delete')
            ->with('/gl-journal-entries/1')
            ->willReturn($mockResponse);

        $result = $this->journalEntries->delete('1');

        $this->assertIsArray($result);
        $this->assertEquals('deleted', $result['status']);
    }

    // ========================================
    // getLines() - Get journal entry lines
    // ========================================

    public function testGetLinesReturnsJournalEntryLines(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => 'line-1',
                    'accountId' => '1000',
                    'accountCode' => '1000',
                    'accountName' => 'Cash',
                    'debit' => '0.00',
                    'credit' => '5000.00',
                    'description' => 'Cash credit',
                ],
                [
                    'id' => 'line-2',
                    'accountId' => '3000',
                    'accountCode' => '3000',
                    'accountName' => 'Revenue',
                    'debit' => '5000.00',
                    'credit' => '0.00',
                    'description' => 'Revenue debit',
                ],
            ],
            'total' => 2,
            'totalDebit' => '5000.00',
            'totalCredit' => '5000.00',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/gl-journal-entries/1/lines')
            ->willReturn($mockResponse);

        $result = $this->journalEntries->getLines('1');

        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']);
        $this->assertEquals('5000.00', $result['totalDebit']);
        $this->assertEquals('5000.00', $result['totalCredit']);
    }

    // ========================================
    // post() - Post a journal entry
    // ========================================

    public function testPostJournalEntry(): void
    {
        $mockResponse = [
            'id' => '1',
            'entryNumber' => 'JE-001',
            'status' => 'posted',
            'postedDate' => '2025-10-30T10:30:00Z',
            'postedBy' => 'system',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('post')
            ->with('/gl-journal-entries/1/post', [])
            ->willReturn($mockResponse);

        $result = $this->journalEntries->post('1');

        $this->assertIsArray($result);
        $this->assertEquals('posted', $result['status']);
        $this->assertEquals('JE-001', $result['entryNumber']);
    }

    // ========================================
    // Integration tests
    // ========================================

    public function testCreateAndGetJournalEntry(): void
    {
        // Create
        $createResponse = [
            'id' => '10',
            'entryNumber' => 'JE-010',
            'description' => 'Test entry',
            'status' => 'draft',
        ];

        // Get
        $getResponse = [
            'id' => '10',
            'entryNumber' => 'JE-010',
            'description' => 'Test entry',
            'status' => 'draft',
            'totalDebit' => '1000.00',
            'totalCredit' => '1000.00',
        ];

        $this->httpClientMock
            ->method('post')
            ->willReturn($createResponse);

        $this->httpClientMock
            ->method('get')
            ->willReturn($getResponse);

        $created = $this->journalEntries->create(['description' => 'Test entry']);
        $this->assertEquals('10', $created['id']);

        $retrieved = $this->journalEntries->get('10');
        $this->assertEquals('10', $retrieved['id']);
        $this->assertEquals('draft', $retrieved['status']);
    }

    public function testCreateGetLinesAndPostSequence(): void
    {
        // Create
        $createResponse = [
            'id' => '20',
            'entryNumber' => 'JE-020',
            'status' => 'draft',
        ];

        // Get lines
        $linesResponse = [
            'data' => [
                [
                    'id' => 'line-1',
                    'accountId' => '1000',
                    'debit' => '0.00',
                    'credit' => '2000.00',
                ],
                [
                    'id' => 'line-2',
                    'accountId' => '5000',
                    'debit' => '2000.00',
                    'credit' => '0.00',
                ],
            ],
            'total' => 2,
            'totalDebit' => '2000.00',
            'totalCredit' => '2000.00',
        ];

        // Post
        $postResponse = [
            'id' => '20',
            'entryNumber' => 'JE-020',
            'status' => 'posted',
            'postedDate' => '2025-10-30T11:00:00Z',
        ];

        $this->httpClientMock
            ->expects($this->exactly(3))
            ->method('post')
            ->willReturnOnConsecutiveCalls($createResponse, $postResponse);

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->willReturn($linesResponse);

        $created = $this->journalEntries->create(['description' => 'Test']);
        $this->assertEquals('20', $created['id']);
        $this->assertEquals('draft', $created['status']);

        $lines = $this->journalEntries->getLines('20');
        $this->assertEquals('2000.00', $lines['totalDebit']);
        $this->assertCount(2, $lines['data']);

        $posted = $this->journalEntries->post('20');
        $this->assertEquals('posted', $posted['status']);
    }

    public function testMultipleLinesBalanceValidation(): void
    {
        $mockResponse = [
            'data' => [
                [
                    'id' => 'line-1',
                    'accountId' => '1000',
                    'debit' => '0.00',
                    'credit' => '1500.00',
                ],
                [
                    'id' => 'line-2',
                    'accountId' => '2000',
                    'debit' => '500.00',
                    'credit' => '0.00',
                ],
                [
                    'id' => 'line-3',
                    'accountId' => '3000',
                    'debit' => '1000.00',
                    'credit' => '0.00',
                ],
            ],
            'total' => 3,
            'totalDebit' => '1500.00',
            'totalCredit' => '1500.00',
        ];

        $this->httpClientMock
            ->expects($this->once())
            ->method('get')
            ->with('/gl-journal-entries/1/lines')
            ->willReturn($mockResponse);

        $lines = $this->journalEntries->getLines('1');

        $this->assertEquals('1500.00', $lines['totalDebit']);
        $this->assertEquals('1500.00', $lines['totalCredit']);
        $this->assertEquals($lines['totalDebit'], $lines['totalCredit']);
    }

    public function testUpdateAndPostSequence(): void
    {
        $updateResponse = [
            'id' => '2',
            'entryNumber' => 'JE-002',
            'description' => 'Updated description',
            'status' => 'draft',
        ];

        $postResponse = [
            'id' => '2',
            'entryNumber' => 'JE-002',
            'description' => 'Updated description',
            'status' => 'posted',
            'postedDate' => '2025-10-30T12:00:00Z',
        ];

        $this->httpClientMock
            ->expects($this->exactly(2))
            ->method('post')
            ->willReturnOnConsecutiveCalls($postResponse);

        $this->httpClientMock
            ->expects($this->once())
            ->method('patch')
            ->willReturn($updateResponse);

        $updated = $this->journalEntries->update('2', ['description' => 'Updated description']);
        $this->assertEquals('draft', $updated['status']);

        $posted = $this->journalEntries->post('2');
        $this->assertEquals('posted', $posted['status']);
    }
}
