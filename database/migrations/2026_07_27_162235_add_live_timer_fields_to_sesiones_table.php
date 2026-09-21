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
            // Momento en que el master paso a "en vivo" en el segmento ACTUAL. Null cuando
            // esta detenido/pausado. Junto con live_accumulated_seconds permite que el
            // cronometro sobreviva recargas de pagina y pausas sin resetearse a cero.
            $table->timestamp('live_started_at')->nullable()->after('voice_consent_at');
            $table->unsignedInteger('live_accumulated_seconds')->default(0)->after('live_started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            $table->dropColumn(['live_started_at', 'live_accumulated_seconds']);
        });
    }
};
