<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Cleanup Expired Password Reset Tokens Command.
 *
 * SECURITY: Removes expired password reset tokens from database (CR-6.3)
 * COMPLIANCE: Prevents token accumulation and reduces attack surface
 *
 * Usage:
 *   php spark auth:cleanup-reset-tokens
 *   php spark auth:cleanup-reset-tokens --dry-run
 *
 * Schedule: Recommended to run daily via cron
 *
 * @package App\Commands
 */
final class CleanupPasswordResetTokens extends BaseCommand
{
    /**
     * @var string Command group
     */
    protected $group = 'Auth';

    /**
     * @var string Command name
     */
    protected $name = 'auth:cleanup-reset-tokens';

    /**
     * @var string Command description
     */
    protected $description = 'Delete expired password reset tokens from database';

    /**
     * @var array<string, string> Command options
     */
    protected $options = [
        '--dry-run' => 'Show what would be deleted without actually deleting',
    ];

    /**
     * Execute command.
     *
     * @param array<int|string, mixed> $params Command parameters
     * @return void
     */
    public function run(array $params): void
    {
        $dryRun = CLI::getOption('dry-run') !== null;

        CLI::write('Password Reset Token Cleanup', 'yellow');
        CLI::write(str_repeat('=', 50));

        if ($dryRun) {
            CLI::write('DRY RUN MODE - No changes will be made', 'cyan');
            CLI::newLine();
        }

        $db = \Config\Database::connect();

        // Count expired tokens
        $expiredCount = $db->table('password_reset_tokens')
            ->where('expires_at <', date('Y-m-d H:i:s'))
            ->countAllResults(false); // Don't reset query

        if ($expiredCount === 0) {
            CLI::write('No expired tokens found.', 'green');
            return;
        }

        CLI::write("Found {$expiredCount} expired token(s)", 'yellow');

        if ($dryRun) {
            // Show sample of what would be deleted
            $sampleTokens = $db->table('password_reset_tokens')
                ->select('user_id, created_at, expires_at')
                ->where('expires_at <', date('Y-m-d H:i:s'))
                ->limit(10)
                ->get()
                ->getResultArray();

            if (count($sampleTokens) > 0) {
                CLI::newLine();
                CLI::write('Sample tokens that would be deleted:', 'cyan');
                CLI::table($sampleTokens, ['user_id', 'created_at', 'expires_at']);
            }

            CLI::newLine();
            CLI::write('Run without --dry-run to delete these tokens', 'cyan');
            return;
        }

        // Delete expired tokens
        $deleted = $db->table('password_reset_tokens')
            ->where('expires_at <', date('Y-m-d H:i:s'))
            ->delete();

        // @phpstan-ignore-next-line (affectedRows can return string in some DB drivers)
        $affectedRows = is_bool($deleted) ? 0 : (int) $db->affectedRows();

        CLI::write("Successfully deleted {$affectedRows} expired token(s)", 'green');

        // Log cleanup
        log_message('info', 'Password reset token cleanup completed', [
            'command' => 'auth:cleanup-reset-tokens',
            'tokens_deleted' => $affectedRows,
        ]);
    }
}
