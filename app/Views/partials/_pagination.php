<?php
/**
 * Bootstrap pagination control (E2).
 *
 * Consumes the pagination meta shape used by paginated query handlers and
 * the ApiResponse envelope:
 *   { page, perPage|per_page, total, lastPage|last_page }
 *
 * Usage:
 *   <?= $this->include('partials/_pagination', [
 *       'page'      => 2,
 *       'last_page' => 5,
 *       'base_url'  => '/cookies',
 *       'preserved' => ['search' => 'choco'],
 *   ]) ?>
 *
 * `preserved` carries forward query parameters such as search terms.
 */

$page = max(1, (int) ($page ?? 1));
$lastPage = max(1, (int) ($last_page ?? $lastPage ?? 1));
$baseUrl = (string) ($base_url ?? $baseUrl ?? '/');
$preserved = is_array($preserved ?? null) ? $preserved : [];

if ($lastPage <= 1) {
    return;
}

$linkFor = static function (int $targetPage) use ($baseUrl, $preserved): string {
    $query = http_build_query(array_merge($preserved, ['page' => $targetPage]));
    $separator = str_contains($baseUrl, '?') ? '&' : '?';
    return $baseUrl . $separator . $query;
};

$prevPage = max(1, $page - 1);
$nextPage = min($lastPage, $page + 1);
$window = 2;
$start = max(1, $page - $window);
$end = min($lastPage, $page + $window);
?>
<nav aria-label="Pagination">
    <ul class="pagination">
        <li class="page-item <?= $page === 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= esc($linkFor($prevPage), 'attr') ?>" aria-label="Previous">
                &laquo; <?= esc(lang('App.previous')) ?>
            </a>
        </li>

        <?php if ($start > 1): ?>
            <li class="page-item">
                <a class="page-link" href="<?= esc($linkFor(1), 'attr') ?>">1</a>
            </li>
            <?php if ($start > 2): ?>
                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
            <?php endif ?>
        <?php endif ?>

        <?php for ($p = $start; $p <= $end; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <?php if ($p === $page): ?>
                    <span class="page-link"><?= (int) $p ?></span>
                <?php else: ?>
                    <a class="page-link" href="<?= esc($linkFor($p), 'attr') ?>"><?= (int) $p ?></a>
                <?php endif ?>
            </li>
        <?php endfor ?>

        <?php if ($end < $lastPage): ?>
            <?php if ($end < $lastPage - 1): ?>
                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
            <?php endif ?>
            <li class="page-item">
                <a class="page-link" href="<?= esc($linkFor($lastPage), 'attr') ?>"><?= (int) $lastPage ?></a>
            </li>
        <?php endif ?>

        <li class="page-item <?= $page >= $lastPage ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= esc($linkFor($nextPage), 'attr') ?>" aria-label="Next">
                <?= esc(lang('App.next')) ?> &raquo;
            </a>
        </li>
    </ul>
</nav>
