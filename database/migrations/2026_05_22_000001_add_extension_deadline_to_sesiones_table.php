<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            if (! Schema::hasColumn('sesiones', 'extension_deadline_at')) {
                $table->timestamp('extension_deadline_at')->nullable()->after('extension_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $table) {
            if (Schema::hasColumn('sesiones', 'extension_deadline_at')) {
                $table->dropColumn('extension_deadline_at');
            }
        });
    }
};
