<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traducciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesion_id')->constrained('sesiones')->cascadeOnDelete();
            $table->text('texto_original');
            $table->text('texto_traducido');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traducciones');
    }
};
