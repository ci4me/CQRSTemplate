<?php
/**
 * Module-aware ERP sidebar (E1 + E3).
 *
 * Items are declared inline so the file stays self-contained — when the
 * module list grows, extract to a config or a SettingsService entry.
 * Each item has a permission guard via the can() helper so users see only
 * what they can act on. The `active` flag is computed by matching the
 * current request URI prefix.
 */

$path = service('uri')->getPath();
$isActive = static fn(string $prefix): bool => str_starts_with('/' . trim($path, '/'), '/' . trim($prefix, '/'));

$items = [
    [
        'label' => lang('App.dashboard'),
        'url' => '/dashboard',
        'icon' => '🏠',
        'permission' => null,
    ],
    [
        'label' => lang('App.cookies'),
        'url' => '/cookies',
        'icon' => '🍪',
        'permission' => 'cookies.view',
    ],
    [
        'label' => lang('App.users'),
        'url' => '/admin/users',
        'icon' => '👥',
        'permission' => 'users.view',
    ],
];
?>
<aside class="bg-light border-end" style="min-width: 240px;">
    <nav class="nav flex-column p-3">
        <?php foreach ($items as $item): ?>
            <?php
            $perm = $item['permission'] ?? null;
            // Show the item only when the actor can act on the module (E3).
            // Items without a permission are public to authenticated users.
            if ($perm !== null && !can($perm)) {
                continue;
            }
            ?>
            <a class="nav-link <?= $isActive($item['url']) ? 'active fw-semibold' : 'text-dark' ?>"
               href="<?= esc((string) $item['url'], 'attr') ?>">
                <span aria-hidden="true"><?= esc((string) $item['icon']) ?></span>
                <?= esc((string) $item['label']) ?>
            </a>
        <?php endforeach ?>
    </nav>
</aside>
