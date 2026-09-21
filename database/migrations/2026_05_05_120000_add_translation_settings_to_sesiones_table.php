<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            if (! Schema::hasColumn('sesiones', 'translation_settings')) {
                $table->json('translation_settings')->nullable()->after('idiomas');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            if (Schema::hasColumn('sesiones', 'translation_settings')) {
                $table->dropColumn('translation_settings');
            }
        });
    }
};
