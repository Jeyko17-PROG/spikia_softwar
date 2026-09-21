<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Crear tabla glosarios si no existe
        if (!Schema::hasTable('glosarios')) {
            Schema::create('glosarios', function (Blueprint $table) {
                $table->id();
                $table->string('titulo');
                $table->text('terminos')->nullable();
                $table->timestamps();
            });
        }

        // 2. AGREGAR COLUMNAS A LA TABLA sesiones (Nombre correcto según tu error)
        Schema::table('sesiones', function (Blueprint $table) {
            // Agregamos glosario_id si no existe
            if (!Schema::hasColumn('sesiones', 'glosario_id')) {
                $table->foreignId('glosario_id')->nullable()->after('id')->constrained('glosarios')->onDelete('set null');
            }
            
            // Agregamos hora_fin si no existe (Para evitar el otro error que tenías)
            if (!Schema::hasColumn('sesiones', 'hora_fin')) {
                $table->time('hora_fin')->nullable()->after('hora_inicio');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            $table->dropForeign(['glosario_id']);
            $table->dropColumn(['glosario_id', 'hora_fin']);
        });
    }
};
