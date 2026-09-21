<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('traducciones', function (Blueprint $table) {
            if (! Schema::hasColumn('traducciones', 'idioma')) {
                $table->string('idioma', 10)->default('es')->after('texto_traducido');
            }
        });
    }

    public function down(): void
    {
        Schema::table('traducciones', function (Blueprint $table) {
            if (Schema::hasColumn('traducciones', 'idioma')) {
                $table->dropColumn('idioma');
            }
        });
    }
};
