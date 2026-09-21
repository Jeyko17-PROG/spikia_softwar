<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            if (! Schema::hasColumn('sesiones', 'has_sign_avatar')) {
                $table->boolean('has_sign_avatar')->default(false)->after('subtitulos');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            if (Schema::hasColumn('sesiones', 'has_sign_avatar')) {
                $table->dropColumn('has_sign_avatar');
            }
        });
    }
};
