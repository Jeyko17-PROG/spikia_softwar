<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Glosario extends Model
{
    protected $table = 'glosarios';

    protected $fillable = [
        'user_id',
        'titulo',
        'idioma',
        'terminos'
    ];

    public function sesiones()
    {
        return $this->hasMany(Sesion::class, 'glosario_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Devuelve una lista plana de TODOS los terminos del glosario (lado origen y destino),
     * deduplicados, listos para alimentar Deepgram keywords / OpenAI prompt.
     */
    public function getTermsList(): array
    {
        return self::parseTermsText((string) ($this->terminos ?? ''));
    }

    /**
     * Devuelve los pares "origen => destino" parseados, util para el prompt de OpenAI
     * cuando se quiere indicar la traduccion exacta de cada termino.
     */
    public function getTermPairs(): array
    {
        $pairs = [];
        $lines = preg_split('/\r\n|\r|\n/', (string) ($this->terminos ?? '')) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (strpos($line, '=>') !== false) {
                [$source, $target] = array_map('trim', explode('=>', $line, 2));
                if ($source !== '' && $target !== '') {
                    $pairs[] = ['source' => $source, 'target' => $target];
                }
            }
        }

        return $pairs;
    }

    /**
     * Parser publico reutilizable. Toma el texto crudo del campo terminos
     * y devuelve un array unico de strings.
     */
    public static function parseTermsText(string $raw): array
    {
        $terms = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Quitar parentesis con descripciones tipo "Termino (descripcion)"
            $cleaned = trim(preg_replace('/\s*\([^)]*\)\s*\.?\s*$/u', '', $line));

            if (strpos($cleaned, '=>') !== false) {
                [$source, $target] = array_map('trim', explode('=>', $cleaned, 2));
                if ($source !== '') $terms[] = $source;
                if ($target !== '') $terms[] = $target;
            } else {
                if ($cleaned !== '') $terms[] = $cleaned;
            }
        }

        // Dedupe case-insensitive preservando primera aparicion
        $seen = [];
        $unique = [];
        foreach ($terms as $t) {
            $key = mb_strtolower($t);
            if (! isset($seen[$key]) && mb_strlen($t) >= 2) {
                $seen[$key] = true;
                $unique[] = $t;
            }
        }

        return $unique;
    }
}

