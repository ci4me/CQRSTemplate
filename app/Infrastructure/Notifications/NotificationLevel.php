<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

/**
 * UI severity hint for a notification (D12).
 */
enum NotificationLevel: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
}
