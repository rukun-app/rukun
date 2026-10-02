<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'rukun';

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $coreUsers = config('database.connections.core.prefix', 'rcore_').'users';
        $schema = $connection->getSchemaBuilder();

        if ($coreUsers === 'users' || ! $schema->hasTable('users')) {
            return;
        }

        if ($schema->hasTable($coreUsers)) {
            throw new RuntimeException('Both users and '.$coreUsers.' exist. Reconcile their identities and references before normalization; no rows have been merged or deleted.');
        }

        // Renaming preserves row IDs, sequence ownership, indexes and incoming foreign keys.
        $schema->rename('users', $coreUsers);
    }

    public function down(): void
    {
        // Forward-only data normalization: earlier migrations now expect the core table.
        // Never recreate a legacy copy during rollback of an already-normalized database.
    }
};
