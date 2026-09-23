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
    // Cada enlace se pinta solo a quien su ruta deja pasar: la facturación, los
    // terceros y los catálogos son de administradores; los usuarios, los
    // servicios, el tipo de cambio y los ajustes, del super administrador (como
    // el menú «Options» del sistema original). Al resto le darían 403.
    $esAdmin = auth()->user()?->isAdmin() ?? false;
    $esSuperAdmin = auth()->user()?->isSuperAdmin() ?? false;

    // El enlace de catálogos lleva al primero que este usuario puede abrir: los
    // marcados `superAdmin` dan 403 a un administrador normal, y `companias`
    // fijo daba 404 en las instalaciones que lo excluyen con `MARCA_CATALOGOS`.
    $primerCatalogo = collect(\App\Support\Catalogs\CatalogRegistry::visibles())
        ->first(fn ($catalogo) => $esSuperAdmin || ! $catalogo->superAdmin);

    $nav = array_merge(
        [[__('Panel'), route('dashboard'), request()->routeIs('dashboard'), 'panel']],
        $esAdmin ? [
            [__('Facturas'), route('transactions.invoice'), request()->routeIs('transactions.invoice'), 'factura'],
            [__('Costos'), route('transactions.bill'), request()->routeIs('transactions.bill'), 'costo'],
            [__('Transacciones'), route('transactions.all'), request()->routeIs('transactions.all', 'transactions.booking'), 'transaccion'],
            [__('Solicitudes de pago'), route('payments.requests'), request()->routeIs('payments.requests'), 'dolar'],
            // Solo con flota propia: quien subcontrata no liquida operadores.
            ...(\App\Support\Expediente::visible('operadorId')
                ? [[__('Liquidaciones'), route('payments.settlements'), request()->routeIs('payments.settlements'), 'recibo']]
                : []),
            // La nómina se apaga entera en quien ya la lleva en otro sistema.
            ...(config('marca.nomina')
                ? [[__('Nómina'), route('payments.payroll'), request()->routeIs('payments.payroll'), 'nomina']]
                : []),
            // El taller solo con flota propia: quien subcontrata no repara nada.
            ...(config('marca.taller') && \App\Support\Expediente::visible('unidadId')
                ? [
                    [__('Mantenimiento'), route('workshop.maintenance'), request()->routeIs('workshop.maintenance'), 'taller'],
                    [__('Almacén'), route('workshop.inventory'), request()->routeIs('workshop.inventory'), 'almacen'],
                ]
                : []),
        ] : [],
        $esSuperAdmin
            ? [[__('Usuarios'), route('users'), request()->routeIs('users'), 'usuarios']]
            : [],
        // Solo con portada pública (producto de marca blanca) hay solicitudes de
        // demo que leer. En la instalación de un cliente (sin portada) ni se lista.
        (config('marca.landing') && $esSuperAdmin)
            ? [[__('Solicitudes de demostración'), route('demo-requests'), request()->routeIs('demo-requests'), 'usuarios']]
            : [],
        $esAdmin ? [
            [__('Clientes y proveedores'), route('parties.clients'), request()->routeIs('parties.clients', 'parties.providers'), 'contactos'],
            ...($primerCatalogo
                ? [[__('Catálogos'), route('catalogs.show', $primerCatalogo->slug), request()->routeIs('catalogs.*'), 'catalogo']]
                : []),
        ] : [],
        $esSuperAdmin ? [
            [__('Servicios y precios'), route('parties.services'), request()->routeIs('parties.services'), 'servicio'],
            [__('Tipos de cambio'), route('exchange'), request()->routeIs('exchange'), 'cambio'],
        ] : [],
    );

    // Operación: lo que usa todo el personal, con los atajos del menú «Bookings»
    // del sistema original (todos, solo importaciones, solo exportaciones, alta).
    $tipo = request()->query('tipo');
    $enListado = request()->routeIs('operations.bookings');
    $operacion = [
        [__('Bookings'), route('operations.bookings'), request()->routeIs('operations.bookings*') && ! in_array($tipo, ['1', '2'], true) && ! request()->routeIs('operations.bookings.create'), 'operacion'],
        [__('Importaciones'), route('operations.bookings', ['tipo' => 1]), $enListado && $tipo === '1', 'importacion'],
        [__('Exportaciones'), route('operations.bookings', ['tipo' => 2]), $enListado && $tipo === '2', 'exportacion'],
        [__('Nuevo booking'), route('operations.bookings.create'), request()->routeIs('operations.bookings.create'), 'nuevo'],
    ];

    // Los ajustes van al final: se entra una vez a configurarlos y casi
    // nunca más, así que no deben competir con lo que se usa a diario.
    $ajustes = (config('marca.ajustes') && $esSuperAdmin)
        ? [[__('Ajustes'), route('settings'), request()->routeIs('settings'), 'ajustes']]
        : [];

    // Reportes, agrupados y con el nombre que tenían en el sistema original
    // (sin traducir: así los conoce la gente).
    $reportes = array_merge(
        $esAdmin ? [
            ['Booking Profit Report', route('transactions.report.booking'), request()->routeIs('transactions.report.*'), 'reporte'],
            ['Transaction Payments General', route('payments.report.general'), request()->routeIs('payments.report.general'), 'balance'],
            ['Transaction Payments by Customer', route('payments.report.customer'), request()->routeIs('payments.report.customer'), 'cobrar'],
            ['Transaction Payments by Vendor', route('payments.report.vendor'), request()->routeIs('payments.report.vendor'), 'pagar'],
        ] : [],
        [['Continuity Report', route('operations.continuity'), request()->routeIs('operations.continuity'), 'continuidad']],
    );
@endphp

<div class="flex min-h-full"
     x-data="{ sidebar: false, colapsado: (() => { try { return localStorage.getItem('nav_colapsado') === '1' } catch (e) { return false } })(),
               tip: { show: false, text: '', y: 0 },
               verTip(e, texto) { if (! this.colapsado) return; const r = e.currentTarget.getBoundingClientRect(); this.tip = { show: true, text: texto, y: r.top + r.height / 2 } },
               togglar() { this.colapsado = ! this.colapsado; try { localStorage.setItem('nav_colapsado', this.colapsado ? '1' : '0') } catch (e) {} } }"
     x-on:keydown.escape.window="sidebar = false"
     x-on:livewire:navigated.window="sidebar = false">

    {{-- Menú lateral: fijo desde lg, cajón deslizante en móvil. En escritorio se
         puede colapsar a solo iconos (se recuerda en el navegador). --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 transform flex-col border-r border-line bg-panel transition-all duration-200 ease-out lg:translate-x-0"
           :class="[sidebar ? 'translate-x-0' : '-translate-x-full', colapsado ? 'lg:w-16' : 'lg:w-64']">
        <div class="flex h-16 shrink-0 items-center gap-2 border-b border-line px-5" :class="colapsado && 'lg:justify-center lg:px-0'">
            <span :class="colapsado && 'lg:hidden'" class="flex items-center gap-2">
                @include('partials.logo', ['class' => 'text-xl', 'alto' => 'h-8'])
                @if ($etiqueta = \App\Support\Marca::etiqueta())
                    <span class="text-[10px] font-semibold uppercase tracking-widest text-ink-faint">{{ $etiqueta }}</span>
                @endif
            </span>
            <button class="ml-auto rounded-lg p-1.5 text-ink-faint transition hover:bg-raised lg:hidden"
                    x-on:click="sidebar = false" aria-label="{{ __('Cerrar menú') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <nav class="flex flex-1 flex-col gap-0.5 overflow-y-auto p-3 text-sm">
            @foreach ([['', $nav], [__('Operación'), $operacion], [__('Reportes'), $reportes], ['', $ajustes]] as [$seccion, $items])
            @if ($seccion !== '')
                <p class="mt-4 px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-ink-faint" :class="colapsado && 'lg:hidden'">{{ $seccion }}</p>
                <hr class="my-2 hidden border-line" :class="colapsado && 'lg:block'">
            @elseif (! $loop->first && $items !== [])
                <hr class="my-2 border-line">
            @endif
            @foreach ($items as [$label, $href, $active, $icon])
                @php $pendiente = $href === '#'; @endphp
                <a href="{{ $href }}" @if (! $pendiente) wire:navigate @endif
                   @if ($active) aria-current="page" @endif
                   aria-label="{{ $label }}"
                   x-on:mouseenter="verTip($event, @js($label))" x-on:mouseleave="tip.show = false"
                   :class="colapsado && 'lg:justify-center'"
                   class="flex items-center gap-2.5 rounded-lg px-3 py-2 font-medium transition
                          {{ $active ? 'bg-raised text-ink shadow-sm' : 'text-ink-muted hover:bg-raised hover:text-ink' }}
                          {{ $pendiente ? 'cursor-default opacity-50' : '' }}">
                    @include('partials.nav-icon', ['icon' => $icon])
                    <span class="min-w-0 leading-snug" :class="colapsado && 'lg:hidden'">{{ $label }}</span>
                    @if ($active)
                        <span class="ml-auto h-1.5 w-1.5 rounded-full bg-accent-500" :class="colapsado && 'lg:hidden'"></span>
                    @endif
                </a>
            @endforeach
            @endforeach
        </nav>

        <div class="border-t border-line p-3" :class="colapsado && 'lg:hidden'">
            <p class="px-2 text-[11px] leading-relaxed text-ink-faint">
                {{ \App\Support\Marca::nombre() }}<br>
                {{ \App\Support\Marca::pie() }}
            </p>
        </div>
    </aside>

    {{-- Tooltip flotante del menú colapsado: fijo para que no lo corte el scroll
         de la barra. Solo se muestra cuando la barra está en modo iconos. --}}
    <div x-show="tip.show" x-cloak x-transition.opacity.duration.100ms
         class="pointer-events-none fixed left-16 z-50 hidden -translate-y-1/2 translate-x-1 whitespace-nowrap rounded-md bg-ink px-2.5 py-1 text-xs font-medium text-surface shadow-lg lg:block"
         :style="`top: ${tip.y}px`" x-text="tip.text"></div>

    {{-- Fondo oscuro del cajón en móvil --}}
    <div x-show="sidebar" x-cloak x-transition.opacity.duration.200ms
         class="fixed inset-0 z-30 bg-black/50 lg:hidden" x-on:click="sidebar = false"></div>

    {{-- Columna principal --}}
    <div class="flex min-w-0 flex-1 flex-col transition-all duration-200" :class="colapsado ? 'lg:pl-16' : 'lg:pl-64'">
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-surface/85 px-4 backdrop-blur sm:px-6">
            <button class="-ml-1 rounded-lg p-2 text-ink-muted transition hover:bg-raised hover:text-ink lg:hidden"
                    x-on:click="sidebar = true" aria-label="{{ __('Abrir menú') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>

            {{-- Colapsar/expandir la barra (solo escritorio); se recuerda. --}}
            <button class="-ml-1 hidden rounded-lg p-2 text-ink-muted transition hover:bg-raised hover:text-ink lg:inline-flex"
                    x-on:click="togglar()" :aria-pressed="colapsado"
                    :aria-label="colapsado ? '{{ __('Expandir menú') }}' : '{{ __('Colapsar menú') }}'"
                    :title="colapsado ? '{{ __('Expandir menú') }}' : '{{ __('Colapsar menú') }}'">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h10M4 18h16"/>
                </svg>
            </button>

            <h1 class="min-w-0 flex-1 truncate text-sm font-semibold text-ink-soft">{{ $title }}</h1>

            <a href="{{ config('services.legacy.url') ?: 'http://'.request()->getHost() }}" target="_blank" rel="noopener"
               class="btn-ghost shrink-0 !px-3 !py-1.5 text-xs" title="{{ __('Abrir el sistema anterior en otra pestaña') }}">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5h5v5M19 5l-8 8M10 5H5v14h14v-5"/>
                </svg>
                <span class="hidden sm:inline">{{ __('Sistema anterior') }}</span>
            </a>

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
            @if ($fallaBanxico = \App\Support\ExchangeRates::failure())
                <div class="alert-warn mb-4" role="alert">
                    {{ $fallaBanxico === 'token'
                        ? __('El token de Banxico venció o no es válido y el tipo de cambio no se está registrando. Contacte a su administrador.')
                        : __('No se pudo conectar con Banxico y el tipo de cambio no se está registrando. Contacte a su administrador.') }}
                </div>
            @endif
            {{ $slot }}
        </main>
    </div>
</div>
@livewireScripts
</body>
</html>
