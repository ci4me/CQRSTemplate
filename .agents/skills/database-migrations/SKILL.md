---
name: database-migrations
description: Create and run database migrations in the CQRSTemplate repo. Use when adding tables, columns, or modifying schema.
---

# Database Migrations

## Creating a Migration

```bash
php spark make:migration <MigrationName>
```

This creates a file in `app/Database/Migrations/` with a timestamp prefix.

## Migration File Structure

```php
<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOrdersTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            // ... fields ...
            'created_at' => ['type' => 'DATETIME', 'null' => false],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('orders');
    }

    public function down(): void
    {
        $this->forge->dropTable('orders');
    }
}
```

## Adding a Column to an Existing Table

```php
public function up(): void
{
    if ($this->db->fieldExists('column_name', 'table_name')) {
        return;
    }
    $this->forge->addColumn('table_name', [
        'column_name' => [
            'type' => 'VARCHAR',
            'constraint' => 100,
            'null' => true,
            'after' => 'existing_column',
        ],
    ]);
}
```

## Running Migrations

```bash
php spark migrate --all    # Run all namespaced migrations
php spark migrate:rollback # Rollback last batch
php spark migrate:status   # Check migration status
```

## Conventions

- Always use `declare(strict_types=1)`
- Use `addField` with explicit type constraints
- Always include `created_at`, `updated_at`, `deleted_at` for domain tables
- Use `unsigned` for ID fields
- Check `fieldExists()` in `up()` for idempotent column additions
- Naming: `Create<Table>Table`, `Add<Column>To<Table>Table`

## After Creating a Migration

1. Run: `php spark migrate --all`
2. If adding a property to a domain entity, follow the property-addition skill
3. Run `composer check` to verify nothing breaks
