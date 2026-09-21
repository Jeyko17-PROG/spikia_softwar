<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones', function (Blueprint $blueprint) {
            // Agregamos las columnas si no existen
            if (!Schema::hasColumn('sesiones', 'zoom_link')) {
                $blueprint->string('zoom_link')->nullable();
            }
            if (!Schema::hasColumn('sesiones', 'idiomas')) {
                $blueprint->text('idiomas')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $blueprint) {
            $blueprint->dropColumn(['zoom_link', 'idiomas']);
        });
    }
};
