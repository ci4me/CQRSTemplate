<?= $this->extend('layout') ?>

<?= $this->section('content') ?>
<!-- cookies/index -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><?= lang('App.cookies') ?></h1>
    <a href="/cookies/create" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> <?= lang('App.create_new_cookie') ?>
    </a>
</div>

<!-- Search Form -->
<form method="get" action="/cookies" class="mb-4">
    <div class="input-group">
        <input type="text" name="search" class="form-control" value="<?= esc($search ?? '') ?>" placeholder="<?= lang('App.search_cookies_placeholder') ?>">
        <button type="submit" class="btn btn-outline-secondary"><?= lang('App.search') ?></button>
        <?php if (!empty($search)): ?>
            <a href="/cookies" class="btn btn-outline-danger"><?= lang('App.clear') ?></a>
        <?php endif; ?>
    </div>
</form>

<!-- Cookies Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($cookies)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> <?= lang('App.no_cookies_found') ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?= lang('App.id') ?></th>
                            <th><?= lang('App.name') ?></th>
                            <th><?= lang('App.description') ?></th>
                            <th><?= lang('App.price') ?></th>
                            <th><?= lang('App.stock') ?></th>
                            <th><?= lang('App.status') ?></th>
                            <th><?= lang('App.actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cookies as $cookie): ?>
                            <tr>
                                <td><?= esc($cookie->id) ?></td>
                                <td><?= esc($cookie->name) ?></td>
                                <td><?= esc($cookie->description) ?></td>
                                <td><?= esc($cookie->formattedPrice) ?></td>
                                <td>
                                    <span class="badge bg-<?= $cookie->outOfStock ? 'danger' : 'success' ?>">
                                        <?= esc($cookie->stock) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($cookie->isActive): ?>
                                        <span class="badge bg-success"><?= lang('App.active') ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?= lang('App.inactive') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="/cookies/<?= esc($cookie->id, 'url') ?>" class="btn btn-outline-primary"><?= lang('App.view') ?></a>
                                        <a href="/cookies/<?= esc($cookie->id, 'url') ?>/edit" class="btn btn-outline-secondary"><?= lang('App.edit') ?></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Pagination -->
<?php if (isset($pager) && $pager['total'] > 0): ?>
    <nav aria-label="Page navigation" class="mt-4">
        <ul class="pagination justify-content-center">
            <li class="page-item <?= $pager['page'] <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="/cookies?page=<?= $pager['page'] - 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"><?= lang('App.previous') ?></a>
            </li>
            <li class="page-item disabled">
                <span class="page-link"><?= lang('App.page_of', [$pager['page'], $pager['lastPage']]) ?></span>
            </li>
            <li class="page-item <?= $pager['page'] >= $pager['lastPage'] ? 'disabled' : '' ?>">
                <a class="page-link" href="/cookies?page=<?= $pager['page'] + 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"><?= lang('App.next') ?></a>
            </li>
        </ul>
        <p class="text-center text-muted"><?= lang('App.total_count', [$pager['total']]) ?></p>
    </nav>
<?php endif; ?>

<?= $this->endSection() ?>
