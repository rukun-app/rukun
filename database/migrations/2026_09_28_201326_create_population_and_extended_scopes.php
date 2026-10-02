<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $files = DB::connection('core')->getQueryGrammar()->wrapTable('files');
        $users = DB::connection('core')->getQueryGrammar()->wrapTable('users');
        DB::statement("CREATE TABLE households (
            id bigserial PRIMARY KEY, public_id uuid NOT NULL UNIQUE, reference varchar(100) NOT NULL UNIQUE,
            area_id bigint NOT NULL REFERENCES areas(id), area_kind varchar(2) GENERATED ALWAYS AS ('rt') STORED,
            address text NOT NULL, block varchar(50), house_number varchar(50),
            occupancy_status varchar(20) NOT NULL DEFAULT 'occupied' CHECK (occupancy_status IN ('occupied','vacant','rented','other')),
            status varchar(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','moved','inactive')),
            kk_number text, kk_hash char(64) UNIQUE,
            created_at timestamp NOT NULL, updated_at timestamp NOT NULL,
            FOREIGN KEY (area_id, area_kind) REFERENCES areas(id, kind),
            CHECK ((kk_number IS NULL) = (kk_hash IS NULL))
        )");
        DB::statement("CREATE TABLE residents (
            id bigserial PRIMARY KEY, public_id uuid NOT NULL UNIQUE, reference varchar(100) NOT NULL UNIQUE,
            name varchar(100) NOT NULL, birth_date date, phone varchar(20),
            nik text, nik_hash char(64) UNIQUE,
            status varchar(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','moved','deceased','inactive')),
            area_id bigint NOT NULL REFERENCES areas(id), area_kind varchar(2) GENERATED ALWAYS AS ('rt') STORED,
            household_id bigint REFERENCES households(id), user_id bigint UNIQUE REFERENCES {$users}(id),
            created_at timestamp NOT NULL, updated_at timestamp NOT NULL,
            FOREIGN KEY (area_id, area_kind) REFERENCES areas(id, kind),
            CHECK ((nik IS NULL) = (nik_hash IS NULL)),
            CHECK (phone IS NULL OR phone ~ '^\\+628[0-9]{8,11}$')
        )");
        DB::statement("CREATE TABLE household_memberships (
            id bigserial PRIMARY KEY, public_id uuid NOT NULL UNIQUE,
            resident_id bigint NOT NULL REFERENCES residents(id), household_id bigint NOT NULL REFERENCES households(id),
            relationship varchar(10) NOT NULL CHECK (relationship IN ('head','spouse','child','parent','other')),
            starts_at timestamp NOT NULL, ends_at timestamp,
            created_at timestamp NOT NULL, updated_at timestamp NOT NULL,
            CHECK (ends_at IS NULL OR ends_at >= starts_at)
        )");
        DB::statement('CREATE UNIQUE INDEX memberships_one_active ON household_memberships (resident_id) WHERE ends_at IS NULL');
        DB::statement('CREATE INDEX memberships_household_active ON household_memberships (household_id, ends_at)');
        DB::statement('CREATE INDEX residents_area_lookup ON residents (area_id, public_id)');
        DB::statement('CREATE INDEX households_area_lookup ON households (area_id, public_id)');
        DB::statement("CREATE TABLE vendors (
            id bigserial PRIMARY KEY, public_id uuid NOT NULL UNIQUE, name varchar(100) NOT NULL,
            status varchar(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
            created_at timestamp NOT NULL, updated_at timestamp NOT NULL
        )");
        DB::statement('ALTER TABLE role_assignments ADD COLUMN household_id bigint REFERENCES households(id), ADD COLUMN vendor_id bigint REFERENCES vendors(id)');
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_scope_type_check, DROP CONSTRAINT role_assignments_check1');
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignment_scope CHECK (
            (scope_type = 'global' AND area_id IS NULL AND household_id IS NULL AND vendor_id IS NULL) OR
            (scope_type IN ('rw','rt') AND area_id IS NOT NULL AND household_id IS NULL AND vendor_id IS NULL) OR
            (scope_type = 'household' AND household_id IS NOT NULL AND area_id IS NULL AND vendor_id IS NULL) OR
            (scope_type = 'vendor' AND vendor_id IS NOT NULL AND area_id IS NULL AND household_id IS NULL)
        )");
        DB::statement("CREATE TABLE population_exports (file_id bigint PRIMARY KEY REFERENCES {$files}(id) ON DELETE CASCADE, area_id bigint NOT NULL REFERENCES areas(id))");
        DB::statement('CREATE TABLE population_import_rows (
            id bigserial PRIMARY KEY, resident_reference varchar(100) NOT NULL UNIQUE,
            resident_id bigint NOT NULL REFERENCES residents(id), fingerprint char(64) NOT NULL,
            created_at timestamp NOT NULL, updated_at timestamp NOT NULL
        )');
        DB::statement('CREATE TABLE population_import_results (
            id bigserial PRIMARY KEY, transfer_id uuid NOT NULL, resident_id bigint NOT NULL REFERENCES residents(id),
            operation_id bigint REFERENCES account_operations(id), created_at timestamp NOT NULL, updated_at timestamp NOT NULL,
            UNIQUE (transfer_id, resident_id)
        )');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE population_exports, population_import_results, population_import_rows');
        DB::statement("DELETE FROM role_assignments WHERE scope_type IN ('household','vendor')");
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignment_scope, DROP COLUMN household_id, DROP COLUMN vendor_id');
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_scope_type_check CHECK (scope_type IN ('global','rw','rt')), ADD CONSTRAINT role_assignments_check1 CHECK ((scope_type = 'global' AND area_id IS NULL) OR (scope_type IN ('rw','rt') AND area_id IS NOT NULL))");
        DB::statement('DROP TABLE vendors, household_memberships, residents, households');
    }
};
