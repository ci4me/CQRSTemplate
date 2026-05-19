<?= $this->extend('layout') ?>

<?= $this->section('content') ?>
<!-- cookies/edit -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Edit Cookie</h1>
    <a href="/cookies/<?= $cookie->getId() ?>" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Back to Cookie
    </a>
</div>

<?php if (session('errors')): ?>
    <div class="alert alert-danger">
        <h5><i class="bi bi-exclamation-triangle"></i> Validation Errors:</h5>
        <ul class="mb-0">
            <?php foreach (session('errors') as $field => $error): ?>
                <li><?= esc($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-body">
                <form method="post" action="/cookies/<?= $cookie->getId() ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control <?= session('errors.name') ? 'is-invalid' : '' ?>" 
                               id="name" name="name" value="<?= esc(old('name', $cookie->getName()->getValue()), 'attr') ?>" required>
                        <?php if (session('errors.name')): ?>
                            <div class="invalid-feedback"><?= esc(session('errors.name')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control <?= session('errors.description') ? 'is-invalid' : '' ?>" 
                                  id="description" name="description" rows="3"><?= esc(old('description', $cookie->getDescription())) ?></textarea>
                        <?php if (session('errors.description')): ?>
                            <div class="invalid-feedback"><?= esc(session('errors.description')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="price" class="form-label">Price <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control <?= session('errors.price') ? 'is-invalid' : '' ?>" 
                                           id="price" name="price" step="0.01" min="0.01" 
                                           value="<?= esc(old('price', $cookie->getPrice()->toDecimalString()), 'attr') ?>" required>
                                    <?php if (session('errors.price')): ?>
                                        <div class="invalid-feedback"><?= esc(session('errors.price')) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="stock" class="form-label">Stock <span class="text-danger">*</span></label>
                                <input type="number" class="form-control <?= session('errors.stock') ? 'is-invalid' : '' ?>" 
                                       id="stock" name="stock" min="0" value="<?= esc(old('stock', $cookie->getStock()), 'attr') ?>" required>
                                <?php if (session('errors.stock')): ?>
                                    <div class="invalid-feedback"><?= esc(session('errors.stock')) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                   value="1" <?= old('is_active', $cookie->getIsActive()) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">
                                Active (visible to customers)
                            </label>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Update Cookie
                        </button>
                        <a href="/cookies/<?= $cookie->getId() ?>" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card bg-light">
            <div class="card-body">
                <h5 class="card-title">Cookie Information</h5>
                <p class="card-text small">
                    <strong>ID:</strong> <?= $cookie->getId() ?><br>
                    <strong>Created:</strong> <?= $cookie->getCreatedAt() ?><br>
                    <strong>Updated:</strong> <?= $cookie->getUpdatedAt() ?>
                </p>
            </div>
        </div>

        <div class="card bg-light mt-3">
            <div class="card-body">
                <h5 class="card-title">Help</h5>
                <p class="card-text small">
                    <strong>Name:</strong> Unique name for the cookie (3-100 characters).<br>
                    <strong>Description:</strong> Optional description.<br>
                    <strong>Price:</strong> Must be greater than $0.01.<br>
                    <strong>Stock:</strong> Number of units available (0 or more).<br>
                    <strong>Active:</strong> Only active cookies are visible to customers.
                </p>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
