<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Traduccion extends Model
{
    protected $table = 'traducciones';

    protected $fillable = [
        'sesion_id',
        'texto_original',
        'texto_traducido',
        'idioma',
    ];

    public function sesion()
    {
        return $this->belongsTo(Sesion::class);
    }
}
