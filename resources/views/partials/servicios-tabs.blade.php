{{-- Pestañas entre los servicios contratados y las rutas: son las dos caras de
     «cuánto se cobra y cuánto cuesta». Servicios es solo del super administrador. --}}
@php
    $pestanas = array_filter([
        (auth()->user()?->isSuperAdmin() ?? false) ? [__('Servicios y precios'), route('parties.services'), request()->routeIs('parties.services*')] : null,
        \App\Support\Expediente::usa('terrestre') ? [__('Configuración de rutas'), route('rutas.index'), request()->routeIs('rutas.*')] : null,
    ]);
@endphp

@if (count($pestanas) > 1)
    <nav class="flex flex-wrap gap-1.5">
        @foreach ($pestanas as [$etiqueta, $href, $activa])
            <a href="{{ $href }}" wire:navigate @if ($activa) aria-current="page" @endif
               class="rounded-lg px-3 py-1.5 text-xs font-medium transition
                      {{ $activa ? 'bg-accent-500 text-white' : 'border border-line text-ink-muted hover:bg-raised hover:text-ink' }}">
                {{ $etiqueta }}
            </a>
        @endforeach
    </nav>
@endif
