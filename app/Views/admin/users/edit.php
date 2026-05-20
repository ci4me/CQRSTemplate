<?= $this->extend('layout') ?>

<?= $this->section('content') ?>
<!-- admin/users/edit -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Edit User</h1>
    <div class="btn-group">
        <a href="/admin/users/<?= $user->id ?>" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <a href="/admin/users/<?= $user->id ?>/reset-password" class="btn btn-warning">
            <i class="bi bi-key"></i> Reset Password
        </a>
    </div>
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
                <form method="post" action="/admin/users/<?= $user->id ?>">
                    <?= csrf_field() ?>

                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        To change the password, use the "Reset Password" button. This form only updates profile information.
                    </div>

                    <div class="mb-3">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control <?= session('errors.name') ? 'is-invalid' : '' ?>"
                               id="name" name="name" value="<?= esc(old('name', $user->name), 'attr') ?>" required>
                        <?php if (session('errors.name')): ?>
                            <div class="invalid-feedback"><?= esc(session('errors.name')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control <?= session('errors.email') ? 'is-invalid' : '' ?>"
                               id="email" name="email" value="<?= esc(old('email', $user->email), 'attr') ?>" required>
                        <?php if (session('errors.email')): ?>
                            <div class="invalid-feedback"><?= esc(session('errors.email')) ?></div>
                        <?php endif; ?>
                        <div class="form-text">Must be a valid email address and unique in the system.</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select <?= session('errors.role') ? 'is-invalid' : '' ?>"
                                        id="role" name="role" required>
                                    <option value="">Select Role</option>
                                    <option value="admin" <?= old('role', $user->role) === 'admin' ? 'selected' : '' ?>>Admin</option>
                                    <option value="customer" <?= old('role', $user->role) === 'customer' ? 'selected' : '' ?>>Customer</option>
                                </select>
                                <?php if (session('errors.role')): ?>
                                    <div class="invalid-feedback"><?= esc(session('errors.role')) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select <?= session('errors.status') ? 'is-invalid' : '' ?>"
                                        id="status" name="status" required>
                                    <option value="">Select Status</option>
                                    <option value="active" <?= old('status', $user->status) === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= old('status', $user->status) === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                                <?php if (session('errors.status')): ?>
                                    <div class="invalid-feedback"><?= esc(session('errors.status')) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Update User
                        </button>
                        <a href="/admin/users/<?= $user->id ?>" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card bg-light">
            <div class="card-body">
                <h5 class="card-title">User Information</h5>
                <p class="card-text small">
                    <strong>User ID:</strong> <?= (int) $user->id ?><br>
                    <strong>Created:</strong> <?= esc($user->createdAt) ?><br>
                    <strong>Last Updated:</strong> <?= esc($user->updatedAt) ?>
                </p>
            </div>
        </div>

        <div class="card bg-light mt-3">
            <div class="card-body">
                <h5 class="card-title">Role Descriptions</h5>
                <p class="card-text small">
                    <strong>Admin:</strong> Full access to all system features including user management.<br><br>
                    <strong>Customer:</strong> Limited access to customer-facing features only.
                </p>
            </div>
        </div>

        <div class="card bg-light mt-3">
            <div class="card-body">
                <h5 class="card-title">Danger Zone</h5>
                <form method="post" action="/admin/users/<?= $user->id ?>/delete"
                      data-confirm="Are you sure you want to delete this user? This action can be reversed by an administrator.">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger w-100">
                        <i class="bi bi-trash"></i> Delete User
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
