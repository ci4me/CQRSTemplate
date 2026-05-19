<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Cleanup Expired Sessions Command.
 *
 * SECURITY: Removes expired sessions from database (CR-7.3)
 * COMPLIANCE: Reduces database bloat and improves query performance
 *
 * Usage:
 *   php spark auth:cleanup-sessions
 *   php spark auth:cleanup-sessions --dry-run
 *
 * Schedule: Recommended to run daily via cron
 *
 * @package App\Commands
 */
final class CleanupExpiredSessions extends BaseCommand
{
    /**
     * @var string Command group
     */
    protected $group = 'Auth';

    /**
     * @var string Command name
     */
    protected $name = 'auth:cleanup-sessions';

    /**
     * @var string Command description
     */
    protected $description = 'Delete expired sessions from database';

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
     */
    public function run(array $params): void
    {
        $dryRun = CLI::getOption('dry-run') !== null;

        CLI::write('Session Cleanup', 'yellow');
        CLI::write(str_repeat('=', 50));

        if ($dryRun) {
            CLI::write('DRY RUN MODE - No changes will be made', 'cyan');
            CLI::newLine();
        }

        $db = \Config\Database::connect();

        // Count expired sessions
        $expiredCount = $db->table('sessions')
            ->where('expires_at <', date('Y-m-d H:i:s'))
            ->countAllResults(false); // Don't reset query

        if ($expiredCount === 0) {
            CLI::write('No expired sessions found.', 'green');
            return;
        }

        CLI::write("Found {$expiredCount} expired session(s)", 'yellow');

        if ($dryRun) {
            // Show sample of what would be deleted
            $sampleSessions = $db->table('sessions')
                ->select('id, user_id, ip_address, created_at, expires_at')
                ->where('expires_at <', date('Y-m-d H:i:s'))
                ->limit(10)
                ->get()
                ->getResultArray();

            if (count($sampleSessions) > 0) {
                CLI::newLine();
                CLI::write('Sample sessions that would be deleted:', 'cyan');
                CLI::table($sampleSessions, ['id', 'user_id', 'ip_address', 'created_at', 'expires_at']);
            }

            CLI::newLine();
            CLI::write('Run without --dry-run to delete these sessions', 'cyan');
            return;
        }

        // Delete expired sessions
        $deleted = $db->table('sessions')
            ->where('expires_at <', date('Y-m-d H:i:s'))
            ->delete();

        // @phpstan-ignore-next-line (affectedRows can return string in some DB drivers)
        $affectedRows = is_bool($deleted) ? 0 : (int) $db->affectedRows();

        CLI::write("Successfully deleted {$affectedRows} expired session(s)", 'green');

        // Log cleanup
        log_message('info', 'Session cleanup completed', [
            'command' => 'auth:cleanup-sessions',
            'sessions_deleted' => $affectedRows,
        ]);
    }
}
