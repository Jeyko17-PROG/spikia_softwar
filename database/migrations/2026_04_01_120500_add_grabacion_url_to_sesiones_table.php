<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            if (! Schema::hasColumn('sesiones', 'grabacion_url')) {
                $table->string('grabacion_url')->nullable()->after('idioma_activo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            if (Schema::hasColumn('sesiones', 'grabacion_url')) {
                $table->dropColumn('grabacion_url');
            }
        });
    }
};

