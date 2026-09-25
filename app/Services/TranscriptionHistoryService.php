<?php

namespace App\Services;

use App\Models\Sesion;
use App\Models\Transcripcion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TranscriptionHistoryService
{
    public function paginateForUser(User $user, ?string $mode, string $search, int $perPage = 20): LengthAwarePaginator
    {
        $search = trim($search);
        $like = '%' . $this->escapeLike($search) . '%';
        // El "codigo corto" de la sesion (ej. "YHW-VMP") se guarda sin guion en la base
        // (short_code = "YHWVMP") - normalizamos el termino buscado igual (sin espacios ni
        // guiones, en mayusculas) para que buscar "YHW-VMP" o "yhwvmp" encuentre lo mismo.
        $likeCode = '%' . $this->escapeLike(strtoupper(str_replace([' ', '-'], '', $search))) . '%';
        $lastTranscriptionSubquery = Transcripcion::query()
            ->selectRaw('MAX(created_at)')
            ->whereColumn('sesion_id', 'sesiones.id')
            ->where('user_id', $user->id);

        $this->applyModeFilter($lastTranscriptionSubquery, $mode);

        // withTrashed(): una sesion archivada (borrada manualmente o vencida) no deja de tener
        // historial de transcripciones - solo se saca de la lista de trabajo activa.
        $paginator = Sesion::withTrashed()
            ->where('user_id', $user->id)
            ->whereHas('transcripciones', fn (Builder $query) => $this->applyModeFilter($query, $mode))
            ->when($search !== '', function (Builder $query) use ($like, $likeCode, $mode) {
                $query->where(function (Builder $nested) use ($like, $likeCode, $mode) {
                    $nested->where('titulo', 'like', $like)
                        ->orWhere('slug', 'like', $like)
                        ->orWhere('short_code', 'like', $likeCode)
                        ->orWhereHas('transcripciones', function (Builder $transcripciones) use ($like, $mode) {
                            $this->applyModeFilter($transcripciones, $mode)
                                ->where(function (Builder $match) use ($like) {
                                    $match->where('texto', 'like', $like)
                                        ->orWhere('idioma', 'like', $like);
                                });
                        });
                });
            })
            ->select('sesiones.*')
            ->selectSub($lastTranscriptionSubquery, 'last_transcription_at')
            ->orderByDesc('last_transcription_at')
            ->paginate($perPage)
            ->withQueryString();

        $sessions = $paginator->getCollection();

        if ($sessions->isEmpty()) {
            return $paginator;
        }

        $transcripcionesQuery = Transcripcion::query()
            ->where('user_id', $user->id)
            ->whereIn('sesion_id', $sessions->pluck('id'));

        $this->applyModeFilter($transcripcionesQuery, $mode);

        $transcripcionesPorSesion = $transcripcionesQuery
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('sesion_id');

        $searchNeedle = Str::lower($search);
        $searchNeedleCode = Str::lower(str_replace([' ', '-'], '', $search));

        $mapped = $sessions->map(function (Sesion $sesion) use ($transcripcionesPorSesion, $search, $searchNeedle, $searchNeedleCode) {
            $sessionMatchesSearch = $search !== ''
                && (
                    Str::contains(Str::lower((string) $sesion->titulo), $searchNeedle)
                    || Str::contains(Str::lower((string) $sesion->slug), $searchNeedle)
                    || ($searchNeedleCode !== '' && Str::contains(Str::lower((string) $sesion->short_code), $searchNeedleCode))
                );

            $idiomas = collect($transcripcionesPorSesion->get($sesion->id, collect()))
                ->groupBy('idioma')
                ->map(function (Collection $items, string $idioma) use ($search, $searchNeedle, $sessionMatchesSearch) {
                    $filteredItems = $search !== '' && ! $sessionMatchesSearch
                        ? $items->filter(function (Transcripcion $item) use ($searchNeedle) {
                            return Str::contains(Str::lower((string) $item->texto), $searchNeedle)
                                || Str::contains(Str::lower((string) $item->idioma), $searchNeedle);
                        })->values()
                        : $items->values();

                    if ($filteredItems->isEmpty()) {
                        return null;
                    }

                    $latest = $filteredItems->first();
                    $previewItems = (($latest?->modo ?? 'resumen') === 'detalle'
                        ? $filteredItems->take(3)
                        : $filteredItems->take(1))
                        ->values();

                    return [
                        'idioma' => $idioma,
                        'modo' => $latest?->modo ?? 'resumen',
                        'items' => $previewItems,
                        'items_count' => $filteredItems->count(),
                        'texto' => $latest?->texto ?? '',
                        'updated_at' => $latest?->updated_at,
                    ];
                })
                ->filter()
                ->sortByDesc(fn (array $idioma) => optional($idioma['updated_at'])->timestamp ?? 0)
                ->values();

            return [
                'sesion' => $sesion,
                'slug' => $sesion->slug,
                'transcripciones_count' => $idiomas->sum('items_count'),
                'idiomas' => $idiomas,
            ];
        })->filter(function (array $resumen) use ($search) {
            return $search === '' || $resumen['idiomas']->isNotEmpty();
        })->values();

        $paginator->setCollection($mapped);

        return $paginator;
    }

    private function applyModeFilter(Builder $query, ?string $mode): Builder
    {
        // "audio_archivo": filas que solo guardan un fragmento de audio para armar la
        // descarga completa despues (SesionController::archiveAudioSegment), sin texto real -
        // se excluyen siempre de este listado, sin importar el filtro Todos/Detalle elegido.
        $query->where('modo', '!=', 'audio_archivo');

        return $query->when(in_array($mode, ['resumen', 'detalle'], true), fn (Builder $builder) => $builder->where('modo', $mode));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
