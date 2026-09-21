<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones', function (Blueprint $blueprint) {
            // Agregamos la columna 'cuenta' que falta en la base de datos
            $blueprint->string('cuenta')->nullable()->after('presentador');
        });
    }

    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $blueprint) {
            $blueprint->dropColumn('cuenta');
        });
    }
};
