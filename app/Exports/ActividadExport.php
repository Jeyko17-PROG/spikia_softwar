<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

// esto ejecuta la funcion de generar la traduccion

class ActividadExport implements FromCollection, WithHeadings
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function collection()
    {
        return collect($this->data)->map(function ($item) {
            return [
                'Titulo' => $item['titulo'],
                'Presentador' => $item['presentador'],
                'Fecha' => $item['fecha'],
                'Inicio' => $item['hora_inicio'],
                'Fin' => $item['hora_fin'],
                'Idiomas' => $item['idiomas'] ?? '',
                'Transcripciones' => $item['transcripciones_count'],
                'Duracion (h)' => $item['duracion_horas'] ?? 0,
                'Tiempo extra (h)' => $item['extra_time_hours'] ?? 0,
                'Extensiones' => $item['extension_count'] ?? 0,
                'Ultima extension' => ! empty($item['last_extended_at']) ? $item['last_extended_at'] : '',
                'Ultimo texto' => $item['ultimo_texto'] ?? '',
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Titulo de Sesion',
            'Host / Presentador',
            'Fecha',
            'Hora Inicio',
            'Hora Finalizacion',
            'Idiomas',
            'Total Transcripciones',
            'Duracion (h)',
            'Tiempo Extra (h)',
            'Cantidad de Extensiones',
            'Ultima Extension',
            'Ultimo Texto',
        ];
    }
}
