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
        $schema->create('wifi_packages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('area_id')->constrained('areas')->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('payment_type_id')->unique()->constrained('payment_types')->restrictOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('due_day');
            $table->unsignedSmallInteger('settle_day');
            $table->timestamps();
        });
        $schema->create('wifi_customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('area_id')->constrained('areas')->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('package_id')->constrained('wifi_packages')->restrictOnDelete();
            $table->foreignId('household_id')->constrained('households')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->index(['area_id', 'vendor_id']);
        });
        $schema->create('wifi_bills', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained('wifi_customers')->restrictOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained('invoices')->restrictOnDelete();
            $table->date('period');
            $table->timestamps();
            $table->unique(['customer_id', 'period']);
        });
        DB::connection('rukun')->statement('ALTER TABLE wifi_packages ADD CONSTRAINT wifi_package_days CHECK (due_day BETWEEN 1 AND 28 AND settle_day BETWEEN due_day AND 28)');
        DB::connection('rukun')->statement('ALTER TABLE wifi_customers ADD CONSTRAINT wifi_customer_dates CHECK (ends_on IS NULL OR ends_on > starts_on)');
        DB::connection('rukun')->statement('CREATE TRIGGER wifi_bills_immutable BEFORE UPDATE OR DELETE ON wifi_bills FOR EACH ROW EXECUTE FUNCTION billing_immutable_history()');
    }

    public function down(): void
    {
        foreach (['wifi_bills', 'wifi_customers', 'wifi_packages'] as $table) {
            Schema::connection('rukun')->dropIfExists($table);
        }
    }
};
