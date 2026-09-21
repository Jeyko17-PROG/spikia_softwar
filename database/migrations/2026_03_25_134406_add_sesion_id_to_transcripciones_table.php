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
            $table->foreignId('sesion_id')->constrained('sesiones')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcripciones', function (Blueprint $table) {
            $table->dropForeign(['sesion_id']);
            $table->dropColumn('sesion_id');
        });
    }
};

