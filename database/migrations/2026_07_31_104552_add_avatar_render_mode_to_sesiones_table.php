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
            // '3d' (avatar Three.js), 'video' (clip pregrabado/generado), 'human_live'
            // (interprete real via WebRTC, ver spikia:features.sign_avatar_human_live).
            $table->string('avatar_mode', 20)->default('3d')->after('has_sign_avatar');
            // Id del personaje 3D elegido cuando avatar_mode = '3d' (ver AVATAR_CHARACTERS
            // en avatar-engine.js). Null = usa el default del motor.
            $table->string('avatar_character', 40)->nullable()->after('avatar_mode');
            // URL del video pregrabado/generado cuando avatar_mode = 'video'.
            $table->string('avatar_video_url', 500)->nullable()->after('avatar_character');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            $table->dropColumn(['avatar_mode', 'avatar_character', 'avatar_video_url']);
        });
    }
};
