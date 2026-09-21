<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sesiones', function (Blueprint $blueprint) {
            if (! Schema::hasColumn('sesiones', 'meeting_bot_status')) {
                $blueprint->string('meeting_bot_status')->nullable();
            }
            if (! Schema::hasColumn('sesiones', 'meeting_bot_source_lang')) {
                $blueprint->string('meeting_bot_source_lang')->nullable();
            }
            if (! Schema::hasColumn('sesiones', 'bot_ingest_token')) {
                $blueprint->string('bot_ingest_token')->nullable();
            }
            if (! Schema::hasColumn('sesiones', 'meeting_bot_last_heartbeat_at')) {
                $blueprint->timestamp('meeting_bot_last_heartbeat_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sesiones', function (Blueprint $blueprint) {
            $blueprint->dropColumn([
                'meeting_bot_status',
                'meeting_bot_source_lang',
                'bot_ingest_token',
                'meeting_bot_last_heartbeat_at',
            ]);
        });
    }
};
