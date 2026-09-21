<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up()
{
    Schema::table('sesiones', function (Blueprint $table) {
        if (! Schema::hasColumn('sesiones', 'hora_fin')) {
            // Añadimos la columna hora_fin después de hora_inicio
            $table->time('hora_fin')->nullable()->after('hora_inicio');
        }
    });
}

public function down()
{
    Schema::table('sesiones', function (Blueprint $table) {
        if (Schema::hasColumn('sesiones', 'hora_fin')) {
            $table->dropColumn('hora_fin');
        }
    });
}
};
