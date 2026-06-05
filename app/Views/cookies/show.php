<?= $this->extend('layout') ?>

<?= $this->section('content') ?>
<!-- cookies/show -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><?= lang('App.cookie_details') ?></h1>
    <div>
        <a href="/cookies/<?= esc($cookie->id, 'url') ?>/edit" class="btn btn-primary">
            <i class="bi bi-pencil"></i> <?= lang('App.edit') ?>
        </a>
        <a href="/cookies" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> <?= lang('App.back_to_list') ?>
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><?= esc($cookie->name) ?></h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tbody>
                        <tr>
                            <th width="200"><?= lang('App.id') ?>:</th>
                            <td><?= esc($cookie->id) ?></td>
                        </tr>
                        <tr>
                            <th><?= lang('App.name') ?>:</th>
                            <td><?= esc($cookie->name) ?></td>
                        </tr>
                        <tr>
                            <th><?= lang('App.description') ?>:</th>
                            <td><?= esc($cookie->description) ?: '<em class="text-muted">' . lang('App.no_description') . '</em>' ?></td>
                        </tr>
                        <tr>
                            <th><?= lang('App.price') ?>:</th>
                            <td>
                                <span class="fs-5 text-success fw-bold"><?= esc($cookie->formattedPrice) ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?= lang('App.stock') ?>:</th>
                            <td>
                                <span class="badge bg-<?= $cookie->outOfStock ? 'danger' : 'success' ?> fs-6">
                                    <?= esc($cookie->stock) ?> <?= lang('App.units') ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th><?= lang('App.status') ?>:</th>
                            <td>
                                <?php if ($cookie->isActive): ?>
                                    <span class="badge bg-success"><?= lang('App.active') ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?= lang('App.inactive') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?= lang('App.created_at') ?>:</th>
                            <td><?= esc($cookie->createdAt) ?></td>
                        </tr>
                        <tr>
                            <th><?= lang('App.updated_at') ?>:</th>
                            <td><?= esc($cookie->updatedAt) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><?= lang('App.actions') ?></h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="/cookies/<?= esc($cookie->id, 'url') ?>/edit" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> <?= lang('App.edit_cookie') ?>
                    </a>
                    <form method="post" action="/cookies/<?= esc($cookie->id, 'url') ?>/delete" data-confirm="<?= lang('App.delete_cookie_confirm') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-danger w-100">
                            <i class="bi bi-trash"></i> <?= lang('App.delete_cookie') ?>
                        </button>
                    </form>
                    <a href="/cookies" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> <?= lang('App.back_to_list') ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
