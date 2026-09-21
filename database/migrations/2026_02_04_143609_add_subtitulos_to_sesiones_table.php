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
        Schema::table('sesiones', function (Blueprint $table) {
            // Añadimos la columna subtitulos como texto (para guardar el JSON)
            $table->text('subtitulos')->nullable()->after('idiomas');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            // Si deshacemos la migración, eliminamos la columna
            $table->dropColumn('subtitulos');
        });
    }
};

