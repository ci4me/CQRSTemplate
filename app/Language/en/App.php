<?php

declare(strict_types=1);

/**
 * Shared UI strings used across the ERP shell (D8).
 *
 * Look up with `lang('App.key')`. Add keys here in en first; translate to
 * other locales by creating the same key in `app/Language/<locale>/App.php`.
 * Missing keys fall back to the en value.
 */

return [
    // Top-level shell
    'app_name'        => 'ERP Template',
    'dashboard'       => 'Dashboard',
    'sign_in'         => 'Sign in',
    'sign_out'        => 'Sign out',
    'profile'         => 'Profile',
    'settings'        => 'Settings',
    'notifications'   => 'Notifications',
    'no_notifications' => 'No new notifications.',
    'mark_all_read'   => 'Mark all read',

    // Modules
    'cookies'         => 'Cookies',
    'users'           => 'Users',
    'admin'           => 'Admin',

    // Common verbs
    'create'          => 'Create',
    'edit'            => 'Edit',
    'save'            => 'Save',
    'cancel'          => 'Cancel',
    'delete'          => 'Delete',
    'restore'         => 'Restore',
    'search'          => 'Search',
    'filter'          => 'Filter',
    'export'          => 'Export',
    'import'          => 'Import',

    // Common nouns
    'actions'         => 'Actions',
    'status'          => 'Status',
    'name'            => 'Name',
    'price'           => 'Price',
    'stock'           => 'Stock',
    'active'          => 'Active',
    'inactive'        => 'Inactive',
    'created_at'      => 'Created at',
    'updated_at'      => 'Updated at',

    // Validation / flash messages
    'created_success'  => '{0} was created successfully.',
    'updated_success'  => '{0} was updated successfully.',
    'deleted_success'  => '{0} was deleted successfully.',
    'restored_success' => '{0} was restored successfully.',
    'not_found'        => 'The requested {0} was not found.',
    'validation_failed' => 'Please correct the errors below.',

    // Pagination
    'pagination_summary' => 'Showing {0}–{1} of {2}',
    'previous'           => 'Previous',
    'next'               => 'Next',
];
