<?php

declare(strict_types=1);

namespace App\Infrastructure\Numbering;

use App\Domain\Shared\ValueObjects\DocumentNumber;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Issues gapless, formatted document numbers from the `document_sequences`
 * table (D4).
 *
 * Typical use from a command handler:
 *
 *     $no = $numbering->allocate(
 *         series: 'invoice',
 *         scope:  (string) date('Y'),       // fiscal year
 *         prefix: 'INV-' . date('Y') . '-',
 *         padLength: 5,
 *     );
 *     $invoice->setNumber($no->formatted);  // INV-2026-00042
 *
 * Concurrency is handled by performing the increment inside a transaction
 * that wraps a SELECT ... FOR UPDATE on MySQL/Postgres (skipped for SQLite,
 * which is single-writer anyway). When the row doesn't exist yet, it is
 * inserted with the supplied configuration; subsequent calls reuse it.
 *
 * The service is intentionally NOT a singleton — instantiate it once per
 * command. It opens its own transaction so it doesn't depend on the
 * surrounding CommandBus pipeline (although it composes fine inside one:
 * the locking SELECT is still inside the outer transaction).
 */
final class DocumentNumberingService
{
    /**
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     */
    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * allocate.
     *
     * @param string $series
     * @param string $scope
     * @param string $prefix
     * @param string $suffix
     * @param int    $padLength
     * @return DocumentNumber
     * @throws \InvalidArgumentException
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function allocate(
        string $series,
        string $scope = '',
        string $prefix = '',
        string $suffix = '',
        int $padLength = 1
    ): DocumentNumber {
        if ($series === '') {
            throw new \InvalidArgumentException('Document series name is required.');
        }
        if ($padLength < 1 || $padLength > 20) {
            throw new \InvalidArgumentException('padLength must be between 1 and 20.');
        }

        $db = $this->connection();
        $db->transBegin();

        try {
            $row = $this->fetchOrCreateRow($db, $series, $scope, $prefix, $suffix, $padLength);
            $next = $row['current_value'] + 1;

            $db->table('document_sequences')
                ->where('id', $row['id'])
                ->update([
                    'current_value' => $next,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        $formatted = $this->format($row['prefix'], $next, $row['pad_length'], $row['suffix']);
        return DocumentNumber::create($series, $scope, $next, $formatted);
    }

    /**
     * Peek at the current value without bumping it. Useful for display or
     * tests. Returns null if no sequence row exists yet.
     *
     * @param string $series
     * @param string $scope
     * @return int|null
     */
    public function peek(string $series, string $scope = ''): ?int
    {
        $result = $this->connection()
            ->table('document_sequences')
            ->where('series', $series)
            ->where('scope', $scope)
            ->get();

        if ($result === false) {
            return null;
        }

        $row = $result->getRowArray();
        return $row === null ? null : (int) ($row['current_value'] ?? 0);
    }

    /**
     * @param BaseConnection<object|resource|false, object|resource|false> $db
     * @param string                                                       $series
     * @param string                                                       $scope
     * @param string                                                       $prefix
     * @param string                                                       $suffix
     * @param int                                                          $padLength
     * @return array{id:int, current_value:int, prefix:string, suffix:string, pad_length:int}
     */
    private function fetchOrCreateRow(
        BaseConnection $db,
        string $series,
        string $scope,
        string $prefix,
        string $suffix,
        int $padLength
    ): array {
        // CONCURRENCY: lock the sequence row for the rest of this transaction
        // on engines that support row-level locks (MySQL InnoDB, Postgres).
        // Without this, two concurrent allocate() calls would each read the
        // same `current_value` and both write `current_value + 1`, handing
        // out the same number twice — gapless numbering MUST remain gapless.
        // SQLite is single-writer, so its lack of FOR UPDATE is a no-op.
        $platform = strtolower($db->getPlatform());
        if ($platform === 'mysqli' || $platform === 'postgre' || $platform === 'mysql') {
            $escapedSeries = $db->escape($series);
            $escapedScope = $db->escape($scope);
            $db->query(sprintf(
                'SELECT id FROM document_sequences WHERE series = %s AND scope = %s FOR UPDATE',
                $escapedSeries,
                $escapedScope
            ));
        }

        $builder = $db->table('document_sequences')
            ->where('series', $series)
            ->where('scope', $scope);

        $existing = $builder->get();
        if ($existing !== false) {
            $row = $existing->getRowArray();
            if ($row !== null) {
                return [
                    'id' => (int) $row['id'],
                    'current_value' => (int) $row['current_value'],
                    'prefix' => (string) ($row['prefix'] ?? ''),
                    'suffix' => (string) ($row['suffix'] ?? ''),
                    'pad_length' => (int) ($row['pad_length'] ?? 1),
                ];
            }
        }

        $now = date('Y-m-d H:i:s');
        $db->table('document_sequences')->insert([
            'series' => $series,
            'scope' => $scope,
            'prefix' => $prefix,
            'suffix' => $suffix,
            'pad_length' => $padLength,
            'current_value' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => (int) $db->insertID(),
            'current_value' => 0,
            'prefix' => $prefix,
            'suffix' => $suffix,
            'pad_length' => $padLength,
        ];
    }

    /**
     * format.
     *
     * @param string $prefix
     * @param int    $value
     * @param int    $padLength
     * @param string $suffix
     * @return string
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function format(string $prefix, int $value, int $padLength, string $suffix): string
    {
        return $prefix . str_pad((string) $value, $padLength, '0', STR_PAD_LEFT) . $suffix;
    }

    /**
     * @return BaseConnection<object|resource|false, object|resource|false>
     */
    private function connection(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}
