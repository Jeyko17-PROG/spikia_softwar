<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->string('presentador')->nullable();
            $table->string('fecha_inicio')->nullable();
            $table->string('hora_inicio')->nullable();
            $table->string('duracion')->nullable();
            $table->text('idioma')->nullable(); // Guardaremos los idiomas separados por coma
            $table->string('slug')->unique();   // IMPORTANTE: Para las URLs del QR
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones');
    }
};
