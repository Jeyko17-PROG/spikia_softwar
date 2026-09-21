<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'license_plan')) {
                $table->string('license_plan', 20)->nullable()->after('credit_used');
            }
        });

        Schema::table('sesiones', function (Blueprint $table) {
            if (! Schema::hasColumn('sesiones', 'demo_expires_at')) {
                $table->timestamp('demo_expires_at')->nullable()->after('idioma_activo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            if (Schema::hasColumn('sesiones', 'demo_expires_at')) {
                $table->dropColumn('demo_expires_at');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'license_plan')) {
                $table->dropColumn('license_plan');
            }
        });
    }
};
