<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'sesiones_user_created_at_idx');
        });

        Schema::table('transcripciones', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'transcripciones_user_created_at_idx');
            $table->index(['slug', 'idioma', 'created_at'], 'transcripciones_slug_idioma_created_at_idx');
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'videos_user_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropIndex('videos_user_created_at_idx');
        });

        Schema::table('transcripciones', function (Blueprint $table) {
            $table->dropIndex('transcripciones_slug_idioma_created_at_idx');
            $table->dropIndex('transcripciones_user_created_at_idx');
        });

        Schema::table('sesiones', function (Blueprint $table) {
            $table->dropIndex('sesiones_user_created_at_idx');
        });
    }
};
