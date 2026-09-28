<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Settings\SettingsRegistry;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->string('type', 20)->nullable()->after('value');
            $table->string('group', 50)->nullable()->index()->after('type');
        });

        foreach (SettingsRegistry::DEFINITIONS as $key => $definition) {
            DB::table('settings')->where('key', $key)->update([
                'type' => $definition['type'],
                'group' => $definition['group'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropIndex(['group']);
            $table->dropColumn(['type', 'group']);
        });
    }
};
