<?= $this->extend('layout') ?>

<?= $this->section('content') ?>
<!-- cookies/index -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Cookies</h1>
    <a href="/cookies/create" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Create New Cookie
    </a>
</div>

<!-- Search Form -->
<form method="get" action="/cookies" class="mb-4">
    <div class="input-group">
        <input type="text" name="search" class="form-control" value="<?= esc($search ?? '') ?>" placeholder="Search cookies...">
        <button type="submit" class="btn btn-outline-secondary">Search</button>
        <?php if (!empty($search)): ?>
            <a href="/cookies" class="btn btn-outline-danger">Clear</a>
        <?php endif; ?>
    </div>
</form>

<!-- Cookies Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($cookies)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No cookies found.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cookies as $cookie): ?>
                            <tr>
                                <td><?= $cookie->getId() ?></td>
                                <td><?= esc($cookie->getName()->getValue()) ?></td>
                                <td><?= esc($cookie->getDescription()) ?></td>
                                <td><?= $cookie->getPrice()->format() ?></td>
                                <td>
                                    <span class="badge bg-<?= $cookie->isOutOfStock() ? 'danger' : 'success' ?>">
                                        <?= $cookie->getStock() ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($cookie->getIsActive()): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="/cookies/<?= $cookie->getId() ?>" class="btn btn-outline-primary">View</a>
                                        <a href="/cookies/<?= $cookie->getId() ?>/edit" class="btn btn-outline-secondary">Edit</a>
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
                <a class="page-link" href="/cookies?page=<?= $pager['page'] - 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">Previous</a>
            </li>
            <li class="page-item disabled">
                <span class="page-link">Page <?= $pager['page'] ?> of <?= $pager['lastPage'] ?></span>
            </li>
            <li class="page-item <?= $pager['page'] >= $pager['lastPage'] ? 'disabled' : '' ?>">
                <a class="page-link" href="/cookies?page=<?= $pager['page'] + 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">Next</a>
            </li>
        </ul>
        <p class="text-center text-muted">Total: <?= $pager['total'] ?> cookies</p>
    </nav>
<?php endif; ?>

<?= $this->endSection() ?>
