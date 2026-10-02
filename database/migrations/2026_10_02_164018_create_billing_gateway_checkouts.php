<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'rukun';

    public function up(): void
    {
        $db = DB::connection('rukun');
        $users = DB::connection('core')->getQueryGrammar()->wrapTable('users');
        $payments = DB::connection('core')->getQueryGrammar()->wrapTable('payments');
        $db->statement("CREATE TABLE gateway_checkouts (
            id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL,
            invoice_id bigint NOT NULL REFERENCES invoices(id), area_id bigint NOT NULL REFERENCES areas(id),
            household_id bigint NOT NULL REFERENCES households(id), actor_id bigint NOT NULL REFERENCES {$users}(id),
            payment_id bigint UNIQUE NOT NULL REFERENCES {$payments}(id), request_key varchar(128) NOT NULL,
            amount bigint NOT NULL CHECK(amount > 0), status varchar(20) NOT NULL DEFAULT 'reserved' CHECK(status IN ('reserved','completed','released','review')),
            receipt_id bigint UNIQUE REFERENCES receipts(id), review_reason varchar(100), expires_at timestamp NOT NULL,
            created_at timestamp NOT NULL, updated_at timestamp NOT NULL, UNIQUE(actor_id, request_key)
        )");
        $db->statement("CREATE UNIQUE INDEX gateway_invoice_reservation ON gateway_checkouts(invoice_id) WHERE status IN ('reserved','review')");
        $db->statement("ALTER TABLE receipts DROP CONSTRAINT receipts_channel_check, ADD CONSTRAINT receipts_channel_check CHECK(channel IN ('cash','manual','gateway'))");
        $db->statement("ALTER TABLE receipts ADD COLUMN gateway_payment_id bigint UNIQUE REFERENCES {$payments}(id), ADD COLUMN paid_at timestamp");
        $db->statement("CREATE TABLE gateway_settlements (
            id bigserial PRIMARY KEY, public_id uuid UNIQUE NOT NULL, checkout_id bigint UNIQUE NOT NULL REFERENCES gateway_checkouts(id),
            gross bigint NOT NULL CHECK(gross > 0), fee bigint NOT NULL CHECK(fee >= 0 AND fee <= gross), net bigint NOT NULL CHECK(net = gross - fee),
            settled_on date NOT NULL, statement_reference varchar(200) NOT NULL, actor_id bigint NOT NULL REFERENCES {$users}(id),
            request_key varchar(128) NOT NULL, created_at timestamp NOT NULL, updated_at timestamp NOT NULL, UNIQUE(actor_id,request_key)
        )");
        $db->statement('CREATE TRIGGER gateway_settlements_immutable BEFORE UPDATE OR DELETE ON gateway_settlements FOR EACH ROW EXECUTE FUNCTION billing_immutable_history()');
    }

    public function down(): void
    {
        $db = DB::connection('rukun');
        $db->statement('DROP TABLE gateway_settlements, gateway_checkouts');
        $db->statement('ALTER TABLE receipts DROP COLUMN gateway_payment_id, DROP COLUMN paid_at');
        $db->statement("ALTER TABLE receipts DROP CONSTRAINT receipts_channel_check, ADD CONSTRAINT receipts_channel_check CHECK(channel IN ('cash','manual'))");
    }
};
