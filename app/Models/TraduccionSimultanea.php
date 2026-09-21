<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TraduccionSimultanea extends Model
{
    protected $table = 'traducciones_simultaneas';

    protected $fillable = [
        'user_id',
        'sesion_id',
        'translation_mode',
        'speaker_mode',
        'speakers',
        'input_language',
        'output_language',
        'speech_to_text_model',
        'translation_model',
        'text_to_speech_model',
        'voice',
        'master_translation_prompt',
        'input_audio_url',
        'original_transcript',
        'translated_text',
        'output_audio_url',
        'config_json',
        'providers_json',
    ];

    protected $casts = [
        'speakers'      => 'array',
        'config_json'   => 'array',
        'providers_json' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(Sesion::class);
    }
}
