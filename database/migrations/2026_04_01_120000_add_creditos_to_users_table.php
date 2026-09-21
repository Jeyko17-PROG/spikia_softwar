<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'credit_limit')) {
                $table->unsignedInteger('credit_limit')->default(100)->after('remember_token');
            }

            if (! Schema::hasColumn('users', 'credit_used')) {
                $table->unsignedInteger('credit_used')->default(0)->after('credit_limit');
            }

            if (! Schema::hasColumn('users', 'credit_half_alerted_at')) {
                $table->timestamp('credit_half_alerted_at')->nullable()->after('credit_used');
            }
        });

        DB::table('users')->update([
            'credit_limit' => 100,
            'credit_used' => 0,
            'credit_half_alerted_at' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'credit_half_alerted_at')) {
                $table->dropColumn('credit_half_alerted_at');
            }

            if (Schema::hasColumn('users', 'credit_used')) {
                $table->dropColumn('credit_used');
            }

            if (Schema::hasColumn('users', 'credit_limit')) {
                $table->dropColumn('credit_limit');
            }
        });
    }
};

