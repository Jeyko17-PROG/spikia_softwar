<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\User;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'verification_code_hash')) {
                $table->string('verification_code_hash')->nullable()->after('password');
            }

            if (! Schema::hasColumn('users', 'verification_code_expires_at')) {
                $table->timestamp('verification_code_expires_at')->nullable()->after('verification_code_hash');
            }
        });

        foreach (['sesiones', 'transcripciones', 'glosarios', 'videos'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'user_id')) {
                    $table->foreignId('user_id')
                        ->nullable()
                        ->after('id')
                        ->constrained()
                        ->cascadeOnDelete();
                }
            });
        }

        Schema::table('glosarios', function (Blueprint $table) {
            if (! Schema::hasColumn('glosarios', 'idioma')) {
                $table->string('idioma', 10)->nullable()->after('titulo');
            }
        });

        $ownerId = User::query()->orderBy('id')->value('id');

        if ($ownerId) {
            foreach (['sesiones', 'transcripciones', 'glosarios', 'videos'] as $tableName) {
                DB::table($tableName)
                    ->whereNull('user_id')
                    ->update(['user_id' => $ownerId]);
            }
        }
    }

    public function down(): void
    {
        foreach (['videos', 'glosarios', 'transcripciones', 'sesiones'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'user_id')) {
                    $table->dropConstrainedForeignId('user_id');
                }
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'verification_code_expires_at')) {
                $table->dropColumn('verification_code_expires_at');
            }

            if (Schema::hasColumn('users', 'verification_code_hash')) {
                $table->dropColumn('verification_code_hash');
            }
        });

        Schema::table('glosarios', function (Blueprint $table) {
            if (Schema::hasColumn('glosarios', 'idioma')) {
                $table->dropColumn('idioma');
            }
        });
    }
};

