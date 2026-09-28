<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('provider_order_id', 50)->unique();
            $table->string('provider_transaction_id')->nullable()->index();
            $table->string('reference_type', 100);
            $table->string('reference_id', 100);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('IDR');
            $table->string('status', 32)->index();
            $table->string('payment_type', 64)->nullable();
            $table->text('checkout_token')->nullable();
            $table->text('checkout_url')->nullable();
            $table->json('metadata')->nullable();
            $table->json('provider_data')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('payment_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('fingerprint', 64)->unique();
            $table->string('provider_status', 32);
            $table->string('resulting_status', 32);
            $table->json('payload');
            $table->timestampTz('processed_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notifications');
        Schema::dropIfExists('payments');
    }
};
