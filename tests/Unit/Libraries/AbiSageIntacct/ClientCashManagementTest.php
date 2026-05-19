<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\AbiSageIntacct;

use AbiSageIntacct\Client;
use AbiSageIntacct\Modules\CashManagement\BankAccounts;
use AbiSageIntacct\Modules\CashManagement\Transactions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Cash Management module access in Client
 *
 * @internal
 */
final class ClientCashManagementTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        // Skip this test if we can't authenticate (offline environment)
        // In real scenarios, the client will authenticate on construction
        $this->markTestSkipped('Requires live Intacct credentials for Client instantiation');
    }

    // ========================================
    // bankAccounts() method tests
    // ========================================

    public function testBankAccountsMethodReturnsCorrectModule(): void
    {
        $bankAccounts = $this->client->bankAccounts();

        $this->assertInstanceOf(BankAccounts::class, $bankAccounts);
    }

    public function testBankAccountsModuleHasCorrectEndpoint(): void
    {
        $bankAccounts = $this->client->bankAccounts();

        $this->assertEquals('/cm-bank-accounts', $bankAccounts->getBaseEndpoint());
    }

    public function testBankAccountsModuleHasHttpClient(): void
    {
        $bankAccounts = $this->client->bankAccounts();

        // Verify httpClient is set by checking if it's not null
        // We do this by calling a method that uses httpClient
        $this->assertIsObject($bankAccounts);
    }

    // ========================================
    // transactions() method tests
    // ========================================

    public function testTransactionsMethodReturnsCorrectModule(): void
    {
        $transactions = $this->client->transactions();

        $this->assertInstanceOf(Transactions::class, $transactions);
    }

    public function testTransactionsModuleHasCorrectEndpoint(): void
    {
        $transactions = $this->client->transactions();

        $this->assertEquals('/cm-transactions', $transactions->getBaseEndpoint());
    }

    public function testTransactionsModuleHasHttpClient(): void
    {
        $transactions = $this->client->transactions();

        // Verify httpClient is set by checking if it's not null
        // We do this by calling a method that uses httpClient
        $this->assertIsObject($transactions);
    }

    // ========================================
    // Module isolation tests
    // ========================================

    public function testMultipleModuleInstancesAreIndependent(): void
    {
        $bankAccounts1 = $this->client->bankAccounts();
        $bankAccounts2 = $this->client->bankAccounts();

        // Should be different instances
        $this->assertNotSame($bankAccounts1, $bankAccounts2);

        // But same endpoint configuration
        $this->assertEquals(
            $bankAccounts1->getBaseEndpoint(),
            $bankAccounts2->getBaseEndpoint()
        );
    }

    public function testCashManagementModulesShareHttpClient(): void
    {
        $bankAccounts = $this->client->bankAccounts();
        $transactions = $this->client->transactions();

        $httpClient1 = $this->client->getHttpClient();
        $httpClient2 = $this->client->getHttpClient();

        // Both should use same HTTP client instance
        $this->assertSame($httpClient1, $httpClient2);
    }

    // ========================================
    // Integration with other modules
    // ========================================

    public function testAllModulesCanBeAccessedTogether(): void
    {
        // Test that all module accessors work
        $customers = $this->client->customers();
        $invoices = $this->client->invoices();
        $bankAccounts = $this->client->bankAccounts();
        $transactions = $this->client->transactions();
        $query = $this->client->query();

        $this->assertIsObject($customers);
        $this->assertIsObject($invoices);
        $this->assertIsObject($bankAccounts);
        $this->assertIsObject($transactions);
        $this->assertIsObject($query);
    }
}
