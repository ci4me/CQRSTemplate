<?= $this->extend('layout') ?>

<?= $this->section('content') ?>
<!-- admin/users/index -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>User Management</h1>
    <a href="/admin/users/create" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Create New User
    </a>
</div>

<!-- Search and Filter Form -->
<form method="get" action="/admin/users" class="mb-4">
    <div class="row g-3">
        <div class="col-md-6">
            <input type="text" name="search" class="form-control" value="<?= esc($search ?? '') ?>" placeholder="Search by name or email...">
        </div>
        <div class="col-md-2">
            <select name="role" class="form-select">
                <option value="">All Roles</option>
                <option value="admin" <?= ($role ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                <option value="customer" <?= ($role ?? '') === 'customer' ? 'selected' : '' ?>>Customer</option>
            </select>
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select">
                <option value="">All Status</option>
                <option value="active" <?= ($status ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= ($status ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-2">
            <div class="btn-group w-100">
                <button type="submit" class="btn btn-outline-secondary">Search</button>
                <?php if (!empty($search) || !empty($role) || !empty($status)): ?>
                    <a href="/admin/users" class="btn btn-outline-danger">Clear</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<!-- Users Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($users)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No users found.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= $user->id ?></td>
                                <td><?= esc($user->name) ?></td>
                                <td><?= esc($user->email) ?></td>
                                <td>
                                    <span class="badge bg-<?= $user->role === 'admin' ? 'danger' : 'primary' ?>">
                                        <?= esc(ucfirst($user->role)) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user->status === 'active'): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $user->createdAt ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="/admin/users/<?= $user->id ?>" class="btn btn-outline-primary">View</a>
                                        <a href="/admin/users/<?= $user->id ?>/edit" class="btn btn-outline-secondary">Edit</a>
                                        <a href="/admin/users/<?= $user->id ?>/reset-password" class="btn btn-outline-warning">Reset Pass</a>
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
                <a class="page-link" href="/admin/users?page=<?= $pager['page'] - 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= !empty($role) ? '&role=' . urlencode($role) : '' ?><?= !empty($status) ? '&status=' . urlencode($status) : '' ?>">Previous</a>
            </li>
            <li class="page-item disabled">
                <span class="page-link">Page <?= $pager['page'] ?> of <?= $pager['lastPage'] ?></span>
            </li>
            <li class="page-item <?= $pager['page'] >= $pager['lastPage'] ? 'disabled' : '' ?>">
                <a class="page-link" href="/admin/users?page=<?= $pager['page'] + 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?><?= !empty($role) ? '&role=' . urlencode($role) : '' ?><?= !empty($status) ? '&status=' . urlencode($status) : '' ?>">Next</a>
            </li>
        </ul>
        <p class="text-center text-muted">Total: <?= $pager['total'] ?> users</p>
    </nav>
<?php endif; ?>

<?= $this->endSection() ?>
