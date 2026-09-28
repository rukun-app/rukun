<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE areas ADD CONSTRAINT areas_id_kind_unique UNIQUE (id, kind)');
        DB::statement("ALTER TABLE areas ADD COLUMN parent_kind varchar(2) GENERATED ALWAYS AS (CASE WHEN kind = 'rt' THEN 'rw' END) STORED");
        DB::statement('ALTER TABLE areas ADD CONSTRAINT areas_parent_kind FOREIGN KEY (parent_id, parent_kind) REFERENCES areas(id, kind)');
        DB::statement('ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_area_kind FOREIGN KEY (area_id, scope_type) REFERENCES areas(id, kind)');
        DB::statement("ALTER TABLE account_scopes ADD COLUMN area_kind varchar(2) GENERATED ALWAYS AS ('rt') STORED");
        DB::statement('ALTER TABLE account_scopes ADD CONSTRAINT account_scopes_area_kind FOREIGN KEY (area_id, area_kind) REFERENCES areas(id, kind)');
        $users = DB::getQueryGrammar()->wrapTable('users');
        DB::statement("UPDATE {$users} SET email = lower(btrim(email)) WHERE email IS NOT NULL");
        DB::statement("ALTER TABLE {$users} ADD CONSTRAINT rukun_email_normalized CHECK (email IS NULL OR (email <> '' AND email = lower(btrim(email))))");
    }

    public function down(): void
    {
        $users = DB::getQueryGrammar()->wrapTable('users');
        DB::statement("ALTER TABLE {$users} DROP CONSTRAINT rukun_email_normalized");
        DB::statement('ALTER TABLE account_scopes DROP CONSTRAINT account_scopes_area_kind, DROP COLUMN area_kind');
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_area_kind');
        DB::statement('ALTER TABLE areas DROP CONSTRAINT areas_parent_kind, DROP COLUMN parent_kind, DROP CONSTRAINT areas_id_kind_unique');
    }
};
