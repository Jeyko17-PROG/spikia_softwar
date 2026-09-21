<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            if (! Schema::hasColumn('sesiones', 'extra_time_minutes')) {
                $table->unsignedInteger('extra_time_minutes')->default(0)->after('demo_expires_at');
            }
            if (! Schema::hasColumn('sesiones', 'extension_count')) {
                $table->unsignedInteger('extension_count')->default(0)->after('extra_time_minutes');
            }
            if (! Schema::hasColumn('sesiones', 'last_extended_at')) {
                $table->timestamp('last_extended_at')->nullable()->after('extension_count');
            }
        });

        Schema::table('session_usage_events', function (Blueprint $table) {
            if (! Schema::hasColumn('session_usage_events', 'metadata')) {
                $table->json('metadata')->nullable()->after('action');
            }
        });
    }

    public function down(): void
    {
        Schema::table('session_usage_events', function (Blueprint $table) {
            if (Schema::hasColumn('session_usage_events', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });

        Schema::table('sesiones', function (Blueprint $table) {
            foreach (['last_extended_at', 'extension_count', 'extra_time_minutes'] as $column) {
                if (Schema::hasColumn('sesiones', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
