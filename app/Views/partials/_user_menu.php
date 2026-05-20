<?php
/**
 * Top-bar user dropdown + notification bell + locale switcher (E1).
 *
 * Displays only when the visitor is authenticated; sign-in views render
 * a different shell.
 *
 * The notification badge calls Services::notificationService()->countUnread()
 * once per request (no separate AJAX poll). If you need a live count, layer
 * a small Stimulus controller over the badge later.
 */

use App\Infrastructure\Notifications\NotificationService;
use Config\Services;

$session = session();
$displayName = (string) ($session->get('email') ?? lang('App.profile'));
$userId = $session->get('user_id');

$unread = 0;
if (is_int($userId) && $userId > 0) {
    try {
        $unread = (new NotificationService())->countUnread($userId);
    } catch (\Throwable) {
        $unread = 0;
    }
}

$localeResolver = Services::localeResolver();
$currentLocale = $localeResolver->resolve(Services::request());
?>
<div class="d-flex align-items-center gap-3">
    <!-- Notifications bell -->
    <a class="position-relative text-decoration-none text-white" href="/notifications" aria-label="<?= esc(lang('App.notifications')) ?>">
        🔔
        <?php if ($unread > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                <?= (int) $unread ?>
                <span class="visually-hidden"><?= esc(lang('App.notifications')) ?></span>
            </span>
        <?php endif ?>
    </a>

    <!-- Locale switcher -->
    <div class="dropdown">
        <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <?= esc(strtoupper($currentLocale)) ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <?php foreach ($localeResolver->supported() as $code): ?>
                <li>
                    <a class="dropdown-item <?= $code === $currentLocale ? 'active' : '' ?>"
                       href="?locale=<?= esc($code, 'attr') ?>">
                        <?= esc(strtoupper($code)) ?>
                    </a>
                </li>
            <?php endforeach ?>
        </ul>
    </div>

    <!-- User dropdown -->
    <div class="dropdown">
        <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <?= esc($displayName) ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li>
                <form method="post" action="/auth/logout" class="m-0">
                    <?= csrf_field() ?>
                    <button type="submit" class="dropdown-item">
                        <?= esc(lang('App.sign_out')) ?>
                    </button>
                </form>
            </li>
        </ul>
    </div>
</div>
