<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcripciones', function (Blueprint $table) {
            if (! Schema::hasColumn('transcripciones', 'modo')) {
                $table->string('modo')->default('resumen')->after('audio_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transcripciones', function (Blueprint $table) {
            if (Schema::hasColumn('transcripciones', 'modo')) {
                $table->dropColumn('modo');
            }
        });
    }
};

