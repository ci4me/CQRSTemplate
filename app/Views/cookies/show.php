<?= $this->extend('layout') ?>

<?= $this->section('content') ?>
<!-- cookies/show -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Cookie Details</h1>
    <div>
        <a href="/cookies/<?= $cookie->getId() ?>/edit" class="btn btn-primary">
            <i class="bi bi-pencil"></i> Edit
        </a>
        <a href="/cookies" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to List
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><?= esc($cookie->getName()->getValue()) ?></h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tbody>
                        <tr>
                            <th width="200">ID:</th>
                            <td><?= $cookie->getId() ?></td>
                        </tr>
                        <tr>
                            <th>Name:</th>
                            <td><?= esc($cookie->getName()->getValue()) ?></td>
                        </tr>
                        <tr>
                            <th>Description:</th>
                            <td><?= esc($cookie->getDescription()) ?: '<em class="text-muted">No description</em>' ?></td>
                        </tr>
                        <tr>
                            <th>Price:</th>
                            <td>
                                <span class="fs-5 text-success fw-bold"><?= $cookie->getPrice()->format() ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th>Stock:</th>
                            <td>
                                <span class="badge bg-<?= $cookie->isOutOfStock() ? 'danger' : 'success' ?> fs-6">
                                    <?= $cookie->getStock() ?> units
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>Status:</th>
                            <td>
                                <?php if ($cookie->getIsActive()): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Created:</th>
                            <td><?= $cookie->getCreatedAt() ?></td>
                        </tr>
                        <tr>
                            <th>Updated:</th>
                            <td><?= $cookie->getUpdatedAt() ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="/cookies/<?= $cookie->getId() ?>/edit" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> Edit Cookie
                    </a>
                    <form method="post" action="/cookies/<?= $cookie->getId() ?>/delete" data-confirm="Are you sure you want to delete this cookie?">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-danger w-100">
                            <i class="bi bi-trash"></i> Delete Cookie
                        </button>
                    </form>
                    <a href="/cookies" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
