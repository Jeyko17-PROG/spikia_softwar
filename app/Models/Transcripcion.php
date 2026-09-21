<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transcripcion extends Model
{
    protected $table = 'transcripciones';

    protected $fillable = [
        'user_id',
        'sesion_id',
        'slug',
        'texto',
        'idioma',
        'audio_url',
        'modo',
    ];

    public function sesion()
    {
        return $this->belongsTo(Sesion::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}