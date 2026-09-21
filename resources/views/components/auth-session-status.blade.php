@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'text-sm bg-emerald-900/40 border border-emerald-700 text-emerald-300 rounded-lg p-2']) }}>
        {{ $status }}
    </div>
@endif
