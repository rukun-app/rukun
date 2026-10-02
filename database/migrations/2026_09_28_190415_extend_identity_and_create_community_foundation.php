<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->uuid('public_id')->nullable()->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->boolean('must_change_password')->default(false);
        });
        $users = DB::connection('core')->getQueryGrammar()->wrapTable('users');
        $roles = DB::connection('core')->getQueryGrammar()->wrapTable('roles');
        DB::statement("UPDATE {$users} SET public_id = gen_random_uuid()");
        DB::statement("ALTER TABLE {$users} ALTER COLUMN public_id SET NOT NULL");
        DB::statement("CREATE UNIQUE INDEX rukun_users_email_normalized ON {$users} (lower(email))");
        DB::statement("ALTER TABLE {$users} ADD CONSTRAINT rukun_user_identifier CHECK (status <> 'active' OR email IS NOT NULL OR phone IS NOT NULL)");
        DB::statement("ALTER TABLE {$users} ADD CONSTRAINT rukun_phone_normalized CHECK (phone IS NULL OR phone ~ '^\\+628[0-9]{8,11}$')");
        DB::statement("CREATE TABLE areas (
            id bigserial PRIMARY KEY, public_id uuid NOT NULL UNIQUE,
            kind varchar(2) NOT NULL CHECK (kind IN ('rw', 'rt')),
            parent_id bigint REFERENCES areas(id) ON DELETE RESTRICT,
            code varchar(20) NOT NULL, name varchar(100) NOT NULL,
            created_at timestamp NOT NULL, updated_at timestamp NOT NULL,
            CHECK ((kind = 'rw' AND parent_id IS NULL) OR (kind = 'rt' AND parent_id IS NOT NULL))
        )");
        DB::statement('CREATE UNIQUE INDEX areas_code_unique ON areas (kind, COALESCE(parent_id, 0), code)');
        DB::statement("CREATE TABLE role_assignments (
            id bigserial PRIMARY KEY, public_id uuid NOT NULL UNIQUE,
            user_id bigint NOT NULL REFERENCES {$users}(id) ON DELETE RESTRICT,
            role_id bigint NOT NULL REFERENCES {$roles}(id) ON DELETE RESTRICT,
            area_id bigint REFERENCES areas(id) ON DELETE RESTRICT,
            scope_type varchar(10) NOT NULL CHECK (scope_type IN ('global', 'rw', 'rt')),
            starts_at timestamp NOT NULL, ends_at timestamp,
            assigned_by bigint NOT NULL REFERENCES {$users}(id) ON DELETE RESTRICT,
            status varchar(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'revoked')),
            created_at timestamp NOT NULL, updated_at timestamp NOT NULL,
            CHECK (ends_at IS NULL OR ends_at > starts_at),
            CHECK ((scope_type = 'global' AND area_id IS NULL) OR (scope_type IN ('rw', 'rt') AND area_id IS NOT NULL))
        )");
        DB::statement('CREATE INDEX role_assignments_scope_lookup ON role_assignments (user_id, status, area_id, starts_at, ends_at)');
        DB::statement("CREATE TABLE account_scopes (
            id bigserial PRIMARY KEY,
            user_id bigint NOT NULL UNIQUE REFERENCES {$users}(id) ON DELETE RESTRICT,
            area_id bigint NOT NULL REFERENCES areas(id) ON DELETE RESTRICT,
            created_at timestamp NOT NULL, updated_at timestamp NOT NULL
        )");
        DB::statement("CREATE TABLE account_operations (
            id bigserial PRIMARY KEY, public_id uuid NOT NULL UNIQUE,
            actor_id bigint NOT NULL REFERENCES {$users}(id) ON DELETE RESTRICT,
            user_id bigint NOT NULL REFERENCES {$users}(id) ON DELETE RESTRICT,
            kind varchar(20) NOT NULL CHECK (kind IN ('provision', 'recover')),
            idempotency_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL,
            expires_at timestamp NOT NULL, downloaded_at timestamp,
            created_at timestamp NOT NULL, updated_at timestamp NOT NULL,
            UNIQUE (actor_id, idempotency_key)
        )");
    }

    public function down(): void
    {
        foreach (['account_operations', 'account_scopes', 'role_assignments', 'areas'] as $table) {
            DB::statement('DROP TABLE '.$table);
        }
        $users = DB::connection('core')->getQueryGrammar()->wrapTable('users');
        DB::statement("ALTER TABLE {$users} DROP CONSTRAINT rukun_phone_normalized, DROP CONSTRAINT rukun_user_identifier");
        DB::statement('DROP INDEX rukun_users_email_normalized');
        Schema::connection('core')->table('users', function (Blueprint $table): void {
            $table->dropColumn(['public_id', 'phone', 'must_change_password']);
        });
        // Email stays nullable: rollback must not destroy phone-only accounts.
    }
};
