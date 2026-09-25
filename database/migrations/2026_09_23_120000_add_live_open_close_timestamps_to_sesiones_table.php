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
            // Distinto de live_started_at (se limpia cada vez que se pausa/detiene el
            // cronometro): estos dos quedan fijos una vez escritos, para que Registro de
            // Actividad pueda mostrar "cuando se activo el microfono por primera vez" y
            // "cuando termino" sin importar cuantas veces se pauso/reanudo en el medio.
            $table->timestamp('live_first_started_at')->nullable()->after('live_accumulated_seconds');
            $table->timestamp('live_last_stopped_at')->nullable()->after('live_first_started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            $table->dropColumn(['live_first_started_at', 'live_last_stopped_at']);
        });
    }
};
