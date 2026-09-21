<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traducciones_simultaneas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sesion_id')->nullable()->constrained('sesiones')->nullOnDelete();

            $table->string('translation_mode')->default('voice_to_text'); // voice_to_text | voice_to_voice
            $table->string('speaker_mode')->default('single');            // single | multiple
            $table->json('speakers')->nullable();

            $table->string('input_language', 10)->default('es');
            $table->string('output_language', 10)->default('en');

            $table->string('speech_to_text_model')->default('gpt-4o-mini-transcribe');
            $table->string('translation_model')->default('gpt-5.4-mini');
            $table->string('text_to_speech_model')->nullable();
            $table->string('voice')->nullable();

            $table->text('master_translation_prompt');

            $table->string('input_audio_url')->nullable();
            $table->text('original_transcript')->nullable();
            $table->text('translated_text')->nullable();
            $table->string('output_audio_url')->nullable();

            $table->json('config_json')->nullable();
            $table->json('providers_json')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traducciones_simultaneas');
    }
};
