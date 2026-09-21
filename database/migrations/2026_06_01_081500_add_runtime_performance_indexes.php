<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            $table->index(['user_id', 'slug'], 'sesiones_user_slug_idx');
            $table->index(['user_id', 'fecha_inicio', 'hora_fin'], 'sesiones_user_fecha_hora_fin_idx');
            $table->index(['user_id', 'demo_expires_at'], 'sesiones_user_demo_expires_idx');
            $table->index(['user_id', 'extension_deadline_at'], 'sesiones_user_extension_deadline_idx');
        });

        Schema::table('transcripciones', function (Blueprint $table) {
            $table->index(['sesion_id', 'idioma', 'modo', 'updated_at'], 'transcripciones_sesion_idioma_modo_updated_idx');
            $table->index(['user_id', 'sesion_id', 'created_at'], 'transcripciones_user_sesion_created_idx');
        });

        Schema::table('glosarios', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'glosarios_user_created_at_idx');
        });

        Schema::table('session_usage_events', function (Blueprint $table) {
            $table->index(['user_id', 'occurred_at'], 'session_usage_user_occurred_idx');
            $table->index(['sesion_id', 'occurred_at'], 'session_usage_sesion_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::table('session_usage_events', function (Blueprint $table) {
            $table->dropIndex('session_usage_sesion_occurred_idx');
            $table->dropIndex('session_usage_user_occurred_idx');
        });

        Schema::table('glosarios', function (Blueprint $table) {
            $table->dropIndex('glosarios_user_created_at_idx');
        });

        Schema::table('transcripciones', function (Blueprint $table) {
            $table->dropIndex('transcripciones_user_sesion_created_idx');
            $table->dropIndex('transcripciones_sesion_idioma_modo_updated_idx');
        });

        Schema::table('sesiones', function (Blueprint $table) {
            $table->dropIndex('sesiones_user_extension_deadline_idx');
            $table->dropIndex('sesiones_user_demo_expires_idx');
            $table->dropIndex('sesiones_user_fecha_hora_fin_idx');
            $table->dropIndex('sesiones_user_slug_idx');
        });
    }
};
