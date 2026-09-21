<?php

use App\Models\Sesion;
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
            $table->string('short_code', 10)->nullable()->unique()->after('slug');
        });

        // Backfill: las sesiones creadas antes de este cambio no tienen codigo corto.
        Sesion::withTrashed()->whereNull('short_code')->each(function (Sesion $sesion) {
            $sesion->short_code = $this->generateUniqueShortCode();
            $sesion->saveQuietly();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            $table->dropColumn('short_code');
        });
    }

    // Alfabeto sin 0/O/1/I/L: caracteres que se confunden facilmente al transcribir a mano
    // un codigo leido en pantalla o impreso, el mismo problema que resuelven los codigos
    // cortos de couriers/tickets.
    private function generateUniqueShortCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Sesion::withTrashed()->where('short_code', $code)->exists());

        return $code;
    }
};
