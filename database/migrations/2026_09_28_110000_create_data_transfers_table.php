<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_transfers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 100);
            $table->string('direction', 10);
            $table->string('status', 20)->index();
            $table->foreignId('input_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignId('output_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->jsonb('options')->nullable();
            $table->unsignedBigInteger('total_rows')->nullable();
            $table->unsignedBigInteger('processed_rows')->default(0);
            $table->unsignedBigInteger('successful_rows')->default(0);
            $table->unsignedBigInteger('failed_rows')->default(0);
            $table->jsonb('error_summary')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'id']);
            $table->index(['type', 'direction', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_transfers');
    }
};
