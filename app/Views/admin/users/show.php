<?= $this->extend('layout') ?>

<?= $this->section('content') ?>
<!-- admin/users/show -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>User Details</h1>
    <div class="btn-group">
        <a href="/admin/users" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to List
        </a>
        <a href="/admin/users/<?= $user->getId() ?>/edit" class="btn btn-primary">
            <i class="bi bi-pencil"></i> Edit User
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">User Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-bordered">
                    <tbody>
                        <tr>
                            <th width="30%">User ID</th>
                            <td><?= $user->getId() ?></td>
                        </tr>
                        <tr>
                            <th>Name</th>
                            <td><?= esc($user->getName()) ?></td>
                        </tr>
                        <tr>
                            <th>Email</th>
                            <td><?= esc($user->getEmail()->getValue()) ?></td>
                        </tr>
                        <tr>
                            <th>Role</th>
                            <td>
                                <span class="badge bg-<?= $user->getRole()->getValue() === 'admin' ? 'danger' : 'primary' ?>">
                                    <?= esc(ucfirst($user->getRole()->getValue())) ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td>
                                <?php if ($user->getStatus()->getValue() === 'active'): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Created At</th>
                            <td><?= $user->getCreatedAt()->format('Y-m-d H:i:s') ?></td>
                        </tr>
                        <tr>
                            <th>Updated At</th>
                            <td><?= $user->getUpdatedAt()->format('Y-m-d H:i:s') ?></td>
                        </tr>
                        <?php if ($user->getDeletedAt()): ?>
                            <tr>
                                <th>Deleted At</th>
                                <td>
                                    <span class="badge bg-danger">
                                        <?= $user->getDeletedAt()->format('Y-m-d H:i:s') ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <!-- Actions Card -->
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="/admin/users/<?= $user->getId() ?>/edit" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> Edit User
                    </a>
                    <a href="/admin/users/<?= $user->getId() ?>/reset-password" class="btn btn-warning">
                        <i class="bi bi-key"></i> Reset Password
                    </a>
                    <?php if (!$user->getDeletedAt()): ?>
                        <form method="post" action="/admin/users/<?= $user->getId() ?>/delete"
                              onsubmit="return confirm('Are you sure you want to delete this user?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger w-100">
                                <i class="bi bi-trash"></i> Delete User
                            </button>
                        </form>
                    <?php else: ?>
                        <button class="btn btn-secondary w-100" disabled>
                            <i class="bi bi-trash"></i> User Deleted
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Activity Card -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Activity</h5>
            </div>
            <div class="card-body">
                <p class="small mb-2">
                    <strong>Account Age:</strong><br>
                    <?php
                        $accountAge = $user->getCreatedAt()->diff(new DateTimeImmutable());
                        echo $accountAge->days . ' days';
                    ?>
                </p>
                <p class="small mb-2">
                    <strong>Last Updated:</strong><br>
                    <?php
                        $lastUpdate = $user->getUpdatedAt()->diff(new DateTimeImmutable());
                        if ($lastUpdate->days === 0) {
                            echo 'Today';
                        } else {
                            echo $lastUpdate->days . ' days ago';
                        }
                    ?>
                </p>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
