<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('rukun');
        $schema->table('wifi_packages', function (Blueprint $table): void {
            $table->boolean('allow_advance')->default(false);
            $table->unsignedSmallInteger('remit_day')->default(28);
            $table->unsignedSmallInteger('gallon_quota')->default(10);
            $table->unsignedSmallInteger('claim_days')->default(30);
        });
        $db = DB::connection('rukun');
        $users = config('database.connections.core.prefix').'users';
        $db->statement('ALTER TABLE wifi_packages ADD CONSTRAINT wifi_benefit_policy CHECK (remit_day BETWEEN settle_day AND 28 AND gallon_quota BETWEEN 1 AND 1000 AND claim_days BETWEEN 1 AND 365)');
        $db->statement("CREATE TABLE wifi_finance (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, bill_id bigint NOT NULL REFERENCES wifi_bills(id), kind varchar(12) NOT NULL CHECK(kind IN ('remit','recover','reverse')), parent_id bigint REFERENCES wifi_finance(id), reverses_id bigint UNIQUE REFERENCES wifi_finance(id), amount bigint NOT NULL CHECK(amount>0), advance bigint NOT NULL DEFAULT 0 CHECK(advance>=0 AND advance<=amount), posted_on date NOT NULL, channel varchar(10) NOT NULL CHECK(channel IN ('cash','bank')), reference varchar(150) NOT NULL, created_by bigint NOT NULL REFERENCES {$users}(id), created_at timestamp NOT NULL, updated_at timestamp NOT NULL)");
        $db->statement('CREATE TABLE wifi_finance_receipts (finance_id bigint NOT NULL REFERENCES wifi_finance(id), receipt_id bigint NOT NULL REFERENCES receipts(id), amount bigint NOT NULL CHECK(amount>0), PRIMARY KEY(finance_id, receipt_id))');
        $db->statement('CREATE TABLE wifi_finance_ledger (finance_id bigint NOT NULL REFERENCES wifi_finance(id), ledger_id bigint UNIQUE NOT NULL REFERENCES ledger_entries(id), PRIMARY KEY(finance_id, ledger_id))');
        $db->statement("CREATE TABLE gallon_benefits (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, bill_id bigint UNIQUE NOT NULL REFERENCES wifi_bills(id), quota integer NOT NULL CHECK(quota>0), available integer NOT NULL CHECK(available>=0), reserved integer NOT NULL DEFAULT 0 CHECK(reserved>=0), confirmed integer NOT NULL DEFAULT 0 CHECK(confirmed>=0), expires_on date NOT NULL, status varchar(12) NOT NULL DEFAULT 'active' CHECK(status IN ('active','expired','reversed')), created_at timestamp NOT NULL, updated_at timestamp NOT NULL, CHECK(available+reserved+confirmed<=quota))");
        $db->statement("CREATE TABLE gallon_claims (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, benefit_id bigint NOT NULL REFERENCES gallon_benefits(id), vendor_id bigint NOT NULL REFERENCES vendors(id), reference varchar(100) NOT NULL, quantity integer NOT NULL CHECK(quantity>0), status varchar(12) NOT NULL DEFAULT 'reserved' CHECK(status IN ('reserved','delivered','confirmed','reversed','expired')), created_by bigint NOT NULL REFERENCES {$users}(id), created_at timestamp NOT NULL, updated_at timestamp NOT NULL, UNIQUE(vendor_id, reference))");
        $db->statement("CREATE TABLE gallon_entries (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, benefit_id bigint NOT NULL REFERENCES gallon_benefits(id), claim_id bigint REFERENCES gallon_claims(id), event varchar(12) NOT NULL CHECK(event IN ('grant','reserve','claim','confirm','expire','reverse')), available_delta integer NOT NULL, reserved_delta integer NOT NULL, confirmed_delta integer NOT NULL, reason text, created_by bigint REFERENCES {$users}(id), created_at timestamp NOT NULL, updated_at timestamp NOT NULL, UNIQUE(claim_id, event))");
        $db->statement('CREATE UNIQUE INDEX gallon_benefit_events ON gallon_entries(benefit_id,event) WHERE claim_id IS NULL');
        foreach (['wifi_finance_bill_kind' => 'wifi_finance(bill_id,kind)', 'wifi_finance_parent' => 'wifi_finance(parent_id)', 'wifi_finance_receipt_source' => 'wifi_finance_receipts(receipt_id)', 'gallon_claim_benefit' => 'gallon_claims(benefit_id)', 'gallon_entry_benefit' => 'gallon_entries(benefit_id)'] as $name => $definition) {
            $db->statement("CREATE INDEX {$name} ON {$definition}");
        }
        foreach (['wifi_finance', 'wifi_finance_receipts', 'wifi_finance_ledger', 'gallon_entries'] as $table) {
            $db->statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION billing_immutable_history()");
        }
    }

    public function down(): void
    {
        foreach (['gallon_entries', 'gallon_claims', 'gallon_benefits', 'wifi_finance_ledger', 'wifi_finance_receipts', 'wifi_finance'] as $table) {
            Schema::connection('rukun')->dropIfExists($table);
        }
        DB::connection('rukun')->statement('ALTER TABLE wifi_packages DROP CONSTRAINT wifi_benefit_policy');
        Schema::connection('rukun')->table('wifi_packages', fn (Blueprint $table) => $table->dropColumn(['allow_advance', 'remit_day', 'gallon_quota', 'claim_days']));
    }
};
