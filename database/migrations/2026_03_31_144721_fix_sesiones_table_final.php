<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            // Agregamos hora_fin si no existe
            if (!Schema::hasColumn('sesiones', 'hora_fin')) {
                $table->time('hora_fin')->nullable()->after('hora_inicio');
            }
            // Agregamos glosario_id si no existe
            if (!Schema::hasColumn('sesiones', 'glosario_id')) {
                $table->unsignedBigInteger('glosario_id')->nullable()->after('id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            $table->dropColumn(['hora_fin', 'glosario_id']);
        });
    }
};
