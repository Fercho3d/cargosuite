@props(['title' => null])
@php $title ??= __('Panel'); @endphp
<!DOCTYPE html>
@php
    $tema = \App\Support\Theme::current();
    $temaResuelto = \App\Support\Theme::resolved();
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="h-full {{ $temaResuelto === \App\Support\Theme::Dark ? 'dark' : '' }}"
      data-theme="{{ $tema->value }}"
      style="color-scheme: {{ $temaResuelto->value }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · {{ \App\Support\Marca::nombre() }}</title>
    @include('partials.theme-script')
    <style>[x-cloak]{display:none!important}</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @include('partials.marca-colores')
</head>
<body class="h-full antialiased">
@php
    // La facturación solo se enseña a administradores; para los demás roles las
    // rutas responden 403, así que ni siquiera se listan.
    $esAdmin = auth()->user()?->isAdmin() ?? false;

    $nav = array_merge(
        [[__('Panel'), route('dashboard'), request()->routeIs('dashboard'), 'panel']],
        $esAdmin ? [
            [__('Facturas'), route('transactions.invoice'), request()->routeIs('transactions.invoice'), 'factura'],
            [__('Costos'), route('transactions.bill'), request()->routeIs('transactions.bill'), 'costo'],
            [__('Transacciones'), route('transactions.all'), request()->routeIs('transactions.all', 'transactions.booking'), 'transaccion'],
            [__('Utilidad por booking'), route('transactions.report.booking'), request()->routeIs('transactions.report.*'), 'reporte'],
            [__('Solicitudes de pago'), route('payments.requests'), request()->routeIs('payments.requests'), 'banco'],
            // Solo con flota propia: quien subcontrata no liquida operadores.
            ...(\App\Support\Expediente::visible('operadorId')
                ? [[__('Liquidaciones'), route('payments.settlements'), request()->routeIs('payments.settlements'), 'banco']]
                : []),
            [__('Cobros por cliente'), route('payments.report.customer'), request()->routeIs('payments.report.customer'), 'banco'],
            [__('Pagos por proveedor'), route('payments.report.vendor'), request()->routeIs('payments.report.vendor'), 'banco'],
            [__('Cobros y pagos'), route('payments.report.general'), request()->routeIs('payments.report.general'), 'banco'],
        ] : [],
        // Los usuarios los administra solo el super administrador; para el resto
        // la ruta responde 403, así que ni se lista.
        (auth()->user()?->isSuperAdmin() ?? false)
            ? [
                [__('Usuarios'), route('users'), request()->routeIs('users'), 'usuarios'],
                // Sin entrada en el menú, las solicitudes que llegan por la
                // página pública se quedarían ahí sin que nadie las lea.
                [__('Solicitudes de demostración'), route('demo-requests'), request()->routeIs('demo-requests'), 'usuarios'],
            ]
            : [],
        [
            [__('Clientes y proveedores'), route('parties.clients'), request()->routeIs('parties.clients', 'parties.providers'), 'usuarios'],
            [__('Servicios y precios'), route('parties.services'), request()->routeIs('parties.services'), 'costo'],
            [__('Catálogos'), route('catalogs.show', 'companias'), request()->routeIs('catalogs.*'), 'catalogo'],
            [__('Tipos de cambio'), route('exchange'), request()->routeIs('exchange'), 'banco'],
            [__('Operación'), route('operations.bookings'), request()->routeIs('operations.bookings*'), 'operacion'],
            [__('Continuidad'), route('operations.continuity'), request()->routeIs('operations.continuity'), 'reporte'],
        ],
        // Los ajustes van al final: se entra una vez a configurarlos y casi
        // nunca más, así que no deben competir con lo que se usa a diario.
        (auth()->user()?->isSuperAdmin() ?? false)
            ? [[__('Ajustes'), route('settings'), request()->routeIs('settings'), 'ajustes']]
            : [],
    );
@endphp

<div class="flex min-h-full" x-data="{ sidebar: false }" x-on:keydown.escape.window="sidebar = false"
     x-on:livewire:navigated.window="sidebar = false">

    {{-- Menú lateral: fijo desde lg, cajón deslizante en móvil --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 transform flex-col border-r border-line bg-panel transition-transform duration-200 ease-out lg:translate-x-0"
           :class="sidebar ? 'translate-x-0' : '-translate-x-full'">
        <div class="flex h-16 shrink-0 items-center gap-2 border-b border-line px-5">
            @include('partials.logo', ['class' => 'text-xl', 'alto' => 'h-8'])
            @if ($etiqueta = \App\Support\Marca::etiqueta())
                <span class="text-[10px] font-semibold uppercase tracking-widest text-ink-faint">{{ $etiqueta }}</span>
            @endif
            <button class="ml-auto rounded-lg p-1.5 text-ink-faint transition hover:bg-raised lg:hidden"
                    x-on:click="sidebar = false" aria-label="Cerrar menú">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <nav class="flex flex-1 flex-col gap-0.5 overflow-y-auto p-3 text-sm">
            @foreach ($nav as [$label, $href, $active, $icon])
                @php $pendiente = $href === '#'; @endphp
                <a href="{{ $href }}" @if (! $pendiente) wire:navigate @endif
                   @if ($active) aria-current="page" @endif
                   class="flex items-center gap-2.5 rounded-lg px-3 py-2 font-medium transition
                          {{ $active ? 'bg-raised text-ink shadow-sm' : 'text-ink-muted hover:bg-raised hover:text-ink' }}
                          {{ $pendiente ? 'cursor-default opacity-50' : '' }}">
                    @include('partials.nav-icon', ['icon' => $icon])
                    <span class="truncate">{{ $label }}</span>
                    @if ($active)
                        <span class="ml-auto h-1.5 w-1.5 rounded-full bg-accent-500"></span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="border-t border-line p-3">
            <p class="px-2 text-[11px] leading-relaxed text-ink-faint">
                {{ \App\Support\Marca::nombre() }}<br>
                {{ \App\Support\Marca::pie() }}
            </p>
        </div>
    </aside>

    {{-- Fondo oscuro del cajón en móvil --}}
    <div x-show="sidebar" x-cloak x-transition.opacity.duration.200ms
         class="fixed inset-0 z-30 bg-black/50 lg:hidden" x-on:click="sidebar = false"></div>

    {{-- Columna principal --}}
    <div class="flex min-w-0 flex-1 flex-col lg:pl-64">
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-surface/85 px-4 backdrop-blur sm:px-6">
            <button class="-ml-1 rounded-lg p-2 text-ink-muted transition hover:bg-raised hover:text-ink lg:hidden"
                    x-on:click="sidebar = true" aria-label="{{ __('Abrir menú') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>

            <h1 class="min-w-0 flex-1 truncate text-sm font-semibold text-ink-soft">{{ $title }}</h1>

            <x-notice-bell />

            <div class="hidden items-center gap-2 sm:flex">
                <x-locale-toggle />
                <x-theme-toggle />
            </div>

            <div class="relative" x-data="{ open: false }">
                <button x-on:click="open = !open" :aria-expanded="open"
                        class="flex items-center gap-2 rounded-lg px-1.5 py-1.5 text-sm text-ink-soft transition hover:bg-raised sm:px-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-500 text-xs font-bold text-white">
                        {{ strtoupper(mb_substr(auth()->user()->name ?? auth()->user()->username ?? 'U', 0, 1)) }}
                    </span>
                    <span class="hidden max-w-[12rem] truncate md:inline">{{ auth()->user()->name ?? auth()->user()->username }}</span>
                    <svg class="hidden h-4 w-4 text-ink-faint sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
                </button>

                <div x-show="open" x-cloak x-on:click.outside="open = false"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="absolute right-0 mt-2 w-60 rounded-xl border border-line bg-panel p-1.5 shadow-xl">
                    <div class="border-b border-line px-3 pb-2 pt-1.5">
                        <p class="truncate text-sm font-medium text-ink">{{ auth()->user()->name ?? auth()->user()->username }}</p>
                        <p class="truncate text-xs text-ink-faint">{{ auth()->user()->username }}</p>
                    </div>

                    <div class="space-y-2 px-3 py-2 sm:hidden">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm text-ink-muted">{{ __('Idioma') }}</span>
                            <x-locale-toggle />
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm text-ink-muted">{{ __('Tema') }}</span>
                            <x-theme-toggle />
                        </div>
                    </div>

                    <a href="{{ route('security.show') }}" wire:navigate
                       class="block rounded-lg px-3 py-2 text-sm text-ink-muted transition hover:bg-raised hover:text-ink">
                        {{ __('Seguridad y 2FA') }}
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-brand transition hover:bg-raised">
                            {{ __('Cerrar sesión') }}
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main data-pantalla class="page-enter flex-1 p-4 sm:p-6 lg:p-8">
            @include('partials.session-status')
            {{ $slot }}
        </main>
    </div>
</div>
@livewireScripts
</body>
</html>
