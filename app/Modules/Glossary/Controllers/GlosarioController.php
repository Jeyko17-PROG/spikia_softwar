<?php

namespace App\Modules\Glossary\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Glosario;
use Illuminate\Http\Request;

class GlosarioController extends Controller
{
    public function index()
    {
        $glosarios = Glosario::where('user_id', auth()->id())
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('modules.glossary.index', compact('glosarios'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'idioma' => ['nullable', 'string', 'max:10'],
            'terminos' => ['nullable', 'string'],
        ]);

        Glosario::create([
            'user_id' => auth()->id(),
            'titulo' => $data['titulo'],
            'idioma' => $data['idioma'] ?? 'es',
            'terminos' => $data['terminos'] ?? null,
        ]);

        return redirect()->route('glosarios')->with('success', 'Creado');
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'idioma' => ['nullable', 'string', 'max:10'],
            'terminos' => ['nullable', 'string'],
        ]);

        $glosario = Glosario::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $glosario->update([
            'titulo' => $data['titulo'],
            'idioma' => $data['idioma'] ?? 'es',
            'terminos' => $data['terminos'] ?? null,
        ]);

        return redirect()->route('glosarios')->with('success', 'Actualizado');
    }

    public function destroy($id)
    {
        Glosario::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail()
            ->delete();

        return redirect()->route('glosarios');
    }
}
