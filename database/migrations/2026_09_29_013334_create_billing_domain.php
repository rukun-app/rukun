<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $users = DB::getQueryGrammar()->wrapTable('users');
        $files = DB::getQueryGrammar()->wrapTable('files');
        DB::statement("CREATE TABLE payment_types (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, area_id bigint NOT NULL REFERENCES areas(id), code varchar(40) NOT NULL, name varchar(100) NOT NULL, collection_policy varchar(30) NOT NULL CHECK(collection_policy IN ('must_settle_in_period','can_accumulate')), fund_classification varchar(20) NOT NULL CHECK(fund_classification IN ('operational','pass_through')), created_at timestamp NOT NULL, updated_at timestamp NOT NULL, UNIQUE(area_id,code))");
        DB::statement('CREATE TABLE tariffs (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, payment_type_id bigint NOT NULL REFERENCES payment_types(id), amount bigint NOT NULL CHECK(amount>0), starts_at date NOT NULL, ends_at date, created_at timestamp NOT NULL, updated_at timestamp NOT NULL, CHECK(ends_at IS NULL OR ends_at>starts_at))');
        DB::statement("CREATE TABLE invoices (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, area_id bigint NOT NULL REFERENCES areas(id), household_id bigint NOT NULL REFERENCES households(id), payment_type_id bigint NOT NULL REFERENCES payment_types(id), tariff_id bigint NOT NULL REFERENCES tariffs(id), period date NOT NULL CHECK(EXTRACT(DAY FROM period)=1), subject varchar(100) NOT NULL DEFAULT '', amount bigint NOT NULL CHECK(amount>0), due_date date NOT NULL, settle_by date NOT NULL, collection_policy varchar(30) NOT NULL CHECK(collection_policy IN ('must_settle_in_period','can_accumulate')), fund_classification varchar(20) NOT NULL CHECK(fund_classification IN ('operational','pass_through')), state varchar(10) NOT NULL CHECK(state IN ('draft','issued','cancelled')), created_at timestamp NOT NULL, updated_at timestamp NOT NULL, UNIQUE(household_id,payment_type_id,period,subject))");
        DB::statement('CREATE TABLE billing_bank_accounts (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, area_id bigint NOT NULL REFERENCES areas(id), bank_name varchar(100) NOT NULL, account_number varchar(80) NOT NULL, account_holder varchar(100) NOT NULL, created_at timestamp NOT NULL, updated_at timestamp NOT NULL)');
        DB::statement("CREATE TABLE payment_submissions (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, area_id bigint NOT NULL REFERENCES areas(id), household_id bigint NOT NULL REFERENCES households(id), submitted_by bigint NOT NULL REFERENCES {$users}(id), amount bigint NOT NULL CHECK(amount>0), transferred_at date NOT NULL, destination_account_id bigint NOT NULL REFERENCES billing_bank_accounts(id), proof_file_id bigint NOT NULL REFERENCES {$files}(id), note text, allocations jsonb, status varchar(10) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected','cancelled')), reviewed_by bigint REFERENCES {$users}(id), reviewed_at timestamp, review_note text, created_at timestamp NOT NULL, updated_at timestamp NOT NULL)");
        DB::statement("CREATE TABLE receipts (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, number varchar(80) UNIQUE NOT NULL, area_id bigint NOT NULL REFERENCES areas(id), household_id bigint NOT NULL REFERENCES households(id), amount bigint NOT NULL CHECK(amount>0), paid_on date NOT NULL, channel varchar(10) NOT NULL CHECK(channel IN ('cash','manual')), submission_id bigint UNIQUE REFERENCES payment_submissions(id), created_by bigint NOT NULL REFERENCES {$users}(id), created_at timestamp NOT NULL, updated_at timestamp NOT NULL)");
        DB::statement('CREATE TABLE receipt_allocations (id bigserial PRIMARY KEY, receipt_id bigint NOT NULL REFERENCES receipts(id), invoice_id bigint NOT NULL REFERENCES invoices(id), amount bigint NOT NULL CHECK(amount>0), UNIQUE(receipt_id,invoice_id))');
        DB::statement("CREATE TABLE receipt_reversals (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, receipt_id bigint UNIQUE NOT NULL REFERENCES receipts(id), area_id bigint NOT NULL REFERENCES areas(id), posted_on date NOT NULL, reason text NOT NULL, created_by bigint NOT NULL REFERENCES {$users}(id), created_at timestamp NOT NULL, updated_at timestamp NOT NULL)");
        DB::statement("CREATE TABLE expenses (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, area_id bigint NOT NULL REFERENCES areas(id), amount bigint NOT NULL CHECK(amount>0), description text NOT NULL, fund_classification varchar(20) NOT NULL CHECK(fund_classification IN ('operational','pass_through')), channel varchar(10) NOT NULL CHECK(channel IN ('cash','bank')), proof_file_id bigint REFERENCES {$files}(id), status varchar(10) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','approved','posted')), created_by bigint NOT NULL REFERENCES {$users}(id), approved_by bigint REFERENCES {$users}(id), approved_at timestamp, posted_on date, created_at timestamp NOT NULL, updated_at timestamp NOT NULL)");
        DB::statement("CREATE TABLE ledger_entries (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, area_id bigint NOT NULL REFERENCES areas(id), posted_on date NOT NULL, kind varchar(12) NOT NULL CHECK(kind IN ('income','expense','transfer','adjustment','reversal')), amount bigint NOT NULL CHECK(amount<>0), fund_classification varchar(20) NOT NULL CHECK(fund_classification IN ('operational','pass_through')), channel varchar(10) NOT NULL CHECK(channel IN ('cash','bank')), receipt_id bigint REFERENCES receipts(id), expense_id bigint REFERENCES expenses(id), reverses_id bigint UNIQUE REFERENCES ledger_entries(id), transfer_group uuid, reason text, created_by bigint NOT NULL REFERENCES {$users}(id), created_at timestamp NOT NULL, updated_at timestamp NOT NULL)");
        DB::statement("CREATE TABLE accounting_periods (id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, area_id bigint NOT NULL REFERENCES areas(id), period date NOT NULL CHECK(EXTRACT(DAY FROM period)=1), report jsonb NOT NULL, closed_by bigint NOT NULL REFERENCES {$users}(id), closed_at timestamp NOT NULL, created_at timestamp NOT NULL, updated_at timestamp NOT NULL, UNIQUE(area_id,period))");
        DB::statement("CREATE TABLE billing_requests (id bigserial PRIMARY KEY, actor_id bigint NOT NULL REFERENCES {$users}(id), operation varchar(100) NOT NULL, request_key varchar(128) NOT NULL, fingerprint char(64) NOT NULL, result jsonb NOT NULL, UNIQUE(actor_id,operation,request_key))");
        DB::statement('CREATE TABLE billing_notices (id bigserial PRIMARY KEY, invoice_id bigint NOT NULL REFERENCES invoices(id), day date NOT NULL, UNIQUE(invoice_id,day))');
        DB::statement('CREATE INDEX invoices_scope_period ON invoices(area_id,period)');
        DB::statement('CREATE INDEX allocations_invoice ON receipt_allocations(invoice_id)');
        DB::statement('CREATE INDEX ledger_scope_date ON ledger_entries(area_id,posted_on)');
        DB::statement('CREATE INDEX submissions_scope_status ON payment_submissions(area_id,status)');
        DB::unprepared("CREATE FUNCTION billing_immutable_history() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''Billing history is append-only''; END;'");
        foreach (['ledger_entries', 'receipts', 'receipt_allocations', 'receipt_reversals', 'accounting_periods'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION billing_immutable_history()");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE billing_notices,billing_requests,accounting_periods,ledger_entries,expenses,receipt_reversals,receipt_allocations,receipts,payment_submissions,billing_bank_accounts,invoices,tariffs,payment_types');
        DB::statement('DROP FUNCTION billing_immutable_history()');
    }
};
