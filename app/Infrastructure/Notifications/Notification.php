<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

/**
 * Read-model DTO for a notification row (D12).
 */
final readonly class Notification
{
    /**
     * @param int                  $id
     * @param int                  $userId
     * @param int|null             $tenantId
     * @param string               $type
     * @param string               $title
     * @param string|null          $body
     * @param array<string, mixed> $data
     * @param string|null          $url
     * @param NotificationLevel    $level
     * @param string|null          $readAt
     * @param string               $createdAt
     */
    public function __construct(
        public int $id,
        public int $userId,
        public ?int $tenantId,
        public string $type,
        public string $title,
        public ?string $body,
        public array $data,
        public ?string $url,
        public NotificationLevel $level,
        public ?string $readAt,
        public string $createdAt
    ) {
    }

    /**
     * isRead.
     *
     * @return bool
     */
    public function isRead(): bool
    {
        return $this->readAt !== null;
    }
}
