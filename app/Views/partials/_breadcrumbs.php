<?php
/**
 * Bootstrap breadcrumb trail (E2).
 *
 * Usage:
 *   <?= $this->include('partials/_breadcrumbs', [
 *       'items' => [
 *           ['label' => lang('App.dashboard'), 'url' => '/dashboard'],
 *           ['label' => lang('App.cookies'),   'url' => '/cookies'],
 *           ['label' => $cookie->name],   // last item: no url = current page
 *       ],
 *   ]) ?>
 */

$items = is_array($items ?? null) ? $items : [];
if ($items === []) {
    return;
}
$lastIndex = count($items) - 1;
?>
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <?php foreach ($items as $i => $crumb): ?>
            <?php
            $label = (string) ($crumb['label'] ?? '');
            $url = isset($crumb['url']) ? (string) $crumb['url'] : null;
            $isLast = $i === $lastIndex || $url === null;
            ?>
            <li class="breadcrumb-item <?= $isLast ? 'active' : '' ?>" <?= $isLast ? 'aria-current="page"' : '' ?>>
                <?php if ($isLast): ?>
                    <?= esc($label) ?>
                <?php else: ?>
                    <a href="<?= esc((string) $url, 'attr') ?>"><?= esc($label) ?></a>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ol>
</nav>
