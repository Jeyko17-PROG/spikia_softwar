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
        Schema::table('transcripciones', function (Blueprint $table) {
            // Etiqueta libre del orador que dijo esta frase (ej. "Hablante 1", "Maria") -
            // soporte para sesiones con varios hablantes (panel, mesa redonda). Nullable:
            // sesiones de un solo orador (la mayoria) no la usan.
            $table->string('hablante')->nullable()->after('idioma');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcripciones', function (Blueprint $table) {
            $table->dropColumn('hablante');
        });
    }
};
