<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Infrastructure\Logging\CorrelationIdService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Domain-facing API for in-app notifications (D12).
 *
 * Typical flow: a command handler or event listener calls
 *     $notifications->notify(
 *         userId: $invoice->approvedBy,
 *         type: 'invoice.approved',
 *         title: 'Invoice #' . $invoice->number . ' approved',
 *         body: 'Posted to GL by ' . $actor->label,
 *         level: NotificationLevel::Success,
 *         url: "/invoices/{$invoice->id}",
 *     );
 *
 * Reads:
 *  - listFor($userId, unreadOnly?: bool, limit?: int)
 *  - countUnread($userId)
 *
 * State changes:
 *  - markRead($notificationId, $userId)   — single
 *  - markAllRead($userId)
 *
 * markRead enforces ownership: a user can only flag their own
 * notifications, never someone else's.
 */
final class NotificationService
{
    /**
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     */
    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function notify(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
        ?string $url = null,
        NotificationLevel $level = NotificationLevel::Info,
        ?int $tenantId = null
    ): int {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Notification requires a recipient user id.');
        }
        if ($type === '' || $title === '') {
            throw new \InvalidArgumentException('Notification type and title are required.');
        }

        $now = date('Y-m-d H:i:s');

        $this->connection()->table('notifications')->insert([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data_json' => $data === [] ? null : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'url' => $url,
            'level' => $level->value,
            'read_at' => null,
            'correlation_id' => CorrelationIdService::get(),
            'created_at' => $now,
        ]);

        return (int) $this->connection()->insertID();
    }

    /**
     * @return list<Notification>
     */
    public function listFor(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $builder = $this->connection()
            ->table('notifications')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($limit);

        if ($unreadOnly) {
            $builder->where('read_at', null);
        }

        $result = $builder->get();
        if ($result === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->getResultArray();
        return array_map(fn(array $row): Notification => $this->hydrate($row), $rows);
    }

    public function countUnread(int $userId): int
    {
        return (int) $this->connection()
            ->table('notifications')
            ->where('user_id', $userId)
            ->where('read_at', null)
            ->countAllResults();
    }

    /**
     * Returns true if the row was found AND owned by the supplied user.
     */
    public function markRead(int $notificationId, int $userId): bool
    {
        $this->connection()
            ->table('notifications')
            ->where('id', $notificationId)
            ->where('user_id', $userId)
            ->where('read_at', null)
            ->update(['read_at' => date('Y-m-d H:i:s')]);

        return $this->connection()->affectedRows() === 1;
    }

    public function markAllRead(int $userId): int
    {
        $this->connection()
            ->table('notifications')
            ->where('user_id', $userId)
            ->where('read_at', null)
            ->update(['read_at' => date('Y-m-d H:i:s')]);

        return $this->connection()->affectedRows();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Notification
    {
        $data = [];
        $rawJson = $row['data_json'] ?? null;
        if (is_string($rawJson) && $rawJson !== '') {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        return new Notification(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            tenantId: $row['tenant_id'] === null ? null : (int) $row['tenant_id'],
            type: (string) $row['type'],
            title: (string) $row['title'],
            body: $row['body'] === null ? null : (string) $row['body'],
            data: $data,
            url: $row['url'] === null ? null : (string) $row['url'],
            level: NotificationLevel::from((string) $row['level']),
            readAt: $row['read_at'] === null ? null : (string) $row['read_at'],
            createdAt: (string) $row['created_at']
        );
    }

    /**
     * @return BaseConnection<object|resource|false, object|resource|false>
     */
    private function connection(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}
