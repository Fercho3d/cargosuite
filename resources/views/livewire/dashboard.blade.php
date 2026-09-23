@php
    $money = fn ($v) => '$'.number_format((float) $v, 2);
    $entero = fn ($v) => number_format((int) $v);
    $usuario = auth()->user();

    // La gráfica se dibuja a mano: son seis números y una curva, no hace falta
    // traer una librería para eso.
    $serie = $panel['serie'];
    $maximo = max(1, max($serie));
    $puntos = [];
    $ancho = 100;
    $alto = 32;
    $paso = count($serie) > 1 ? $ancho / (count($serie) - 1) : 0;

    foreach (array_values($serie) as $i => $valor) {
        $puntos[] = round($i * $paso, 2).','.round($alto - ($valor / $maximo) * ($alto - 4), 2);
    }

    $linea = implode(' ', $puntos);
    $area = $linea.' '.$ancho.','.$alto.' 0,'.$alto;
@endphp

<div class="mx-auto max-w-6xl space-y-5">

    {{-- Portada --}}
    <section class="relative overflow-hidden rounded-2xl border border-line bg-panel p-4 sm:p-5">
        {{-- Un resplandor del color de la marca, muy tenue, detrás del saludo --}}
        <div class="pointer-events-none absolute -right-24 -top-24 h-64 w-64 rounded-full bg-accent-500/10 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-accent-500/40 to-transparent" aria-hidden="true"></div>

        <div class="relative flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-ink-faint">
                    {{ now()->isoFormat(__('dddd, D [de] MMMM')) }}
                </p>
                <h2 class="mt-0.5 text-xl font-semibold text-ink sm:text-2xl">
                    {{ __('Hola, :nombre', ['nombre' => $usuario->name ?: $usuario->username]) }}
                </h2>
                <p class="mt-1 text-sm text-ink-muted">
                    {{ __('Así va :mes.', ['mes' => \Illuminate\Support\Carbon::parse($panel['mes'])->isoFormat(__('MMMM [de] YYYY'))]) }}
                </p>
            </div>

            <a href="{{ route('operations.bookings') }}" wire:navigate class="btn-accent px-4 py-2 text-sm">
                {{ __('Bookings') }}
            </a>
        </div>

        @unless ($usuario->two_factor_secret)
            <div class="relative mt-3 flex flex-wrap items-center gap-3 rounded-xl border border-accent-700/50 bg-accent-700/10 px-4 py-2 text-sm">
                <span class="text-brand">{{ __('Refuerza tu cuenta activando la verificación en dos pasos.') }}</span>
                <a href="{{ route('security.show') }}" wire:navigate class="btn-accent !px-3 !py-1.5 text-xs">{{ __('Activar 2FA') }}</a>
            </div>
        @endunless
    </section>

    {{-- Cifras del mes --}}
    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['etiqueta' => __('Facturado'), 'valor' => $money($panel['facturado']), 'pie' => __('en el mes, con impuestos')],
            ['etiqueta' => __('Utilidad'), 'valor' => $money($panel['utilidad']), 'pie' => __('a tipo de cambio del documento')],
            ['etiqueta' => __('Embarques'), 'valor' => $entero($panel['embarques']), 'pie' => __('con carga este mes')],
            ['etiqueta' => __('Contenedores'), 'valor' => $entero($panel['contenedores']), 'pie' => __('en esos embarques')],
        ] as $tarjeta)
            <div class="card group relative overflow-hidden p-5 transition hover:border-accent-500/40">
                <div class="pointer-events-none absolute inset-x-0 bottom-0 h-px bg-gradient-to-r from-transparent via-accent-500/0 to-transparent transition group-hover:via-accent-500/50" aria-hidden="true"></div>
                <p class="text-xs font-medium uppercase tracking-wide text-ink-faint">{{ $tarjeta['etiqueta'] }}</p>
                <p class="mt-2 truncate text-2xl font-semibold tabular-nums text-ink" title="{{ $tarjeta['valor'] }}">{{ $tarjeta['valor'] }}</p>
                <p class="mt-1 text-xs text-ink-faint">{{ $tarjeta['pie'] }}</p>
            </div>
        @endforeach
    </section>

    {{-- Mapa de rutas en curso: arriba, tras las cifras del mes. --}}
    @include('partials.mapa-rutas')

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- Facturas emitidas por mes --}}
        <section class="card p-5 lg:col-span-2">
            <div class="flex items-baseline justify-between gap-3">
                <h3 class="text-sm font-semibold text-ink">{{ __('Facturas emitidas') }}</h3>
                <span class="text-xs text-ink-faint">{{ __('últimos :n meses', ['n' => count($serie)]) }}</span>
            </div>

            <svg viewBox="0 0 100 {{ $alto }}" preserveAspectRatio="none" class="mt-4 h-24 w-full" role="img"
                 aria-label="{{ __('Facturas emitidas por mes') }}">
                <defs>
                    <linearGradient id="pulso" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="var(--color-accent-500)" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="var(--color-accent-500)" stop-opacity="0"/>
                    </linearGradient>
                </defs>
                <polygon points="{{ $area }}" fill="url(#pulso)"/>
                <polyline points="{{ $linea }}" fill="none" stroke="var(--color-accent-500)" stroke-width="1.2"
                          stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
            </svg>

            <div class="mt-2 flex justify-between text-[11px] tabular-nums text-ink-faint">
                @foreach ($serie as $mes => $valor)
                    <span class="text-center">
                        {{ \Illuminate\Support\Carbon::parse($mes.'-01')->translatedFormat('M') }}
                        <span class="block font-medium text-ink-muted">{{ $valor }}</span>
                    </span>
                @endforeach
            </div>
        </section>

        {{-- Lo que espera a alguien --}}
        <section class="card p-5">
            <div class="flex items-baseline justify-between gap-3">
                <h3 class="text-sm font-semibold text-ink">{{ __('Pendientes') }}</h3>
                <a href="{{ route('notifications') }}" wire:navigate class="text-xs text-brand hover:underline">
                    {{ __('Ver todos los avisos') }}
                </a>
            </div>

            {{-- Los dos pendientes llevan a facturación, que es solo de
                 administradores: a los demás roles esos enlaces les responderían
                 403, así que ni se calculan ni se pintan. --}}
            @isset ($panel['pendientes'])
                <ul class="mt-3 space-y-2">
                    @foreach ([
                        ['n' => $panel['pendientes']['timbrar'], 'texto' => __('facturas sin timbrar'), 'ruta' => route('transactions.invoice')],
                        ['n' => $panel['pendientes']['solicitudes'], 'texto' => __('solicitudes de pago abiertas'), 'ruta' => route('payments.requests')],
                    ] as $pendiente)
                        <li>
                            <a href="{{ $pendiente['ruta'] }}" wire:navigate
                               class="flex items-center justify-between gap-3 rounded-xl border border-line px-4 py-3 transition hover:border-accent-500/40 hover:bg-raised">
                                <span class="text-sm text-ink-muted">{{ $pendiente['texto'] }}</span>
                                <span class="text-lg font-semibold tabular-nums {{ $pendiente['n'] > 0 ? 'text-brand' : 'text-ink-faint' }}">
                                    {{ $entero($pendiente['n']) }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 rounded-xl border border-line px-4 py-3 text-sm text-ink-faint">
                    {{ __('Aquí aparecen tus avisos sin leer.') }}
                </p>
            @endisset
        </section>
    </div>

    {{-- Lo que viene --}}
    <section class="card overflow-hidden">
        <header class="flex items-center justify-between gap-3 border-b border-line px-5 py-3">
            <h3 class="text-sm font-semibold text-ink">{{ __('Próximos movimientos') }}</h3>
            <span class="text-xs text-ink-faint">{{ __('siguientes 10 días') }}</span>
        </header>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-5 py-2.5 text-left font-semibold">{{ __('Booking') }}</th>
                        <th class="px-5 py-2.5 text-left font-semibold">{{ __('Cliente') }}</th>
                        <th class="px-5 py-2.5 text-left font-semibold">{{ __('Carga') }}</th>
                        <th class="px-5 py-2.5 text-left font-semibold">{{ __('Arribo') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($panel['proximos'] as $fila)
                        <tr class="transition hover:bg-raised">
                            <td class="whitespace-nowrap px-5 py-2.5">
                                <a href="{{ route('operations.bookings.show', $fila['booking_id']) }}" wire:navigate
                                   class="text-brand hover:underline">{{ trim((string) $fila['booking_number']) ?: '#'.$fila['booking_id'] }}</a>
                            </td>
                            <td class="max-w-[18rem] truncate px-5 py-2.5 text-ink-muted">{{ $fila['cliente'] ?: '—' }}</td>
                            <td class="whitespace-nowrap px-5 py-2.5 text-ink-muted">
                                {{ $fila['loading_EDT'] ? \Illuminate\Support\Carbon::parse($fila['loading_EDT'])->format('d/m/Y') : '—' }}
                            </td>
                            <td class="whitespace-nowrap px-5 py-2.5 text-ink-muted">
                                {{ $fila['dicharge_ETA'] ? \Illuminate\Support\Carbon::parse($fila['dicharge_ETA'])->format('d/m/Y') : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-10 text-center text-ink-faint">
                                {{ __('No hay embarques con carga o arribo en los próximos días.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

</div>
