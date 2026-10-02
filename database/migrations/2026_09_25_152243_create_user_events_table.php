<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('core')->create('user_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 100);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('resource_type')->nullable();
            $table->string('resource_id')->nullable();
            $table->jsonb('payload');
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'id']);
            $table->index(['user_id', 'type', 'id']);
            $table->index(['resource_type', 'resource_id']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('core')->dropIfExists('user_events');
    }
};
