@props(['class' => 'text-xl', 'alto' => 'h-8'])
@php use App\Support\Marca; @endphp

{{--
    Logotipo. Se dibuja de dos maneras y la elige la configuración, no la vista:
    si `marca.logo.imagen.claro` tiene algo se pinta la imagen del cliente, y si
    no, el logotipo de letra (dos mitades, la segunda en el color de acento).
    Así una instalación recién sacada de la caja ya tiene marca sin que nadie
    haya diseñado un archivo.

    `class` fija el tamaño de la letra y `alto` el de la imagen.
--}}
@if (Marca::usaImagen())
    @if (Marca::tieneLogoOscuro())
        {{-- Las dos imágenes van en el HTML y el tema enseña una: cambiar el
             `src` con JavaScript parpadearía al navegar con wire:navigate. --}}
        <img src="{{ Marca::logo('claro') }}" alt="{{ Marca::nombre() }}"
             class="{{ $alto }} w-auto dark:hidden">
        <img src="{{ Marca::logo('oscuro') }}" alt="{{ Marca::nombre() }}"
             class="{{ $alto }} hidden w-auto dark:block">
    @else
        <img src="{{ Marca::logo('claro') }}" alt="{{ Marca::nombre() }}"
             class="{{ $alto }} w-auto">
    @endif
@else
    <span class="inline-flex items-baseline font-extrabold tracking-tight {{ $class }}">
        <span class="text-ink">{{ config('marca.logo.texto.principal') }}</span><span class="text-accent-500">{{ config('marca.logo.texto.acento') }}{{ config('marca.logo.texto.simbolo') }}</span>
    </span>
@endif
