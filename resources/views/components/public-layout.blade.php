@props(['title' => null])
@php
    use App\Support\Marca;

    $tema = \App\Support\Theme::current();
    $temaResuelto = \App\Support\Theme::resolved();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="h-full {{ $temaResuelto === \App\Support\Theme::Dark ? 'dark' : '' }}"
      data-theme="{{ $tema->value }}"
      style="color-scheme: {{ $temaResuelto->value }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? Marca::nombre() }}</title>

    {{-- Esta página sí la ve gente de fuera: conviene que se presente bien
         cuando alguien la comparta por correo o por WhatsApp. --}}
    <meta name="description" content="{{ __('Sistema de operación, facturación y cobranza para agentes de carga. Expedientes, contenedores, CFDI, costos por proveedor, solicitudes de pago y portal para clientes y proveedores.') }}">
    <meta property="og:title" content="{{ Marca::nombre() }}">
    <meta property="og:description" content="{{ __('Sistema de operación, facturación y cobranza para agentes de carga.') }}">
    <meta property="og:type" content="website">

    @include('partials.theme-script')
    <style>[x-cloak]{display:none!important}</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @include('partials.marca-colores')
</head>
<body class="h-full antialiased">
<div class="flex min-h-full flex-col bg-surface">
    <header class="sticky top-0 z-20 border-b border-line bg-surface/85 backdrop-blur">
        <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4 sm:px-6">
            <a href="/" class="flex items-baseline gap-2">
                @include('partials.logo', ['class' => 'text-xl', 'alto' => 'h-8'])
            </a>

            <div class="ml-auto flex items-center gap-2">
                <div class="hidden sm:flex sm:items-center sm:gap-2">
                    <x-locale-toggle />
                    <x-theme-toggle />
                </div>

                <a href="{{ route('login') }}" wire:navigate
                   class="rounded-lg border border-line px-3 py-1.5 text-sm font-medium text-ink-soft transition hover:bg-raised">
                    {{ __('Entrar') }}
                </a>
            </div>
        </div>
    </header>

    <main class="flex-1">{{ $slot }}</main>

    <footer class="border-t border-line px-4 py-6 text-center text-xs text-ink-faint">
        &copy; {{ date('Y') }} {{ Marca::nombre() }}
        @if ($sitio = trim((string) config('marca.empresa.sitio'))) · <a href="{{ $sitio }}" class="hover:text-ink-muted">{{ $sitio }}</a> @endif
    </footer>
</div>
@livewireScripts
</body>
</html>
