@php
    use App\Support\Dashboard\RouteMap;

    $fondo = trim((string) config('marca.mapa_fondo'));
@endphp

{{--
    Mapa de rutas del panel.

    Es un SVG que sirve la propia aplicación: nada de Google Maps ni Mapbox. Sin
    llave que gestionar, sin factura por carga, sin mandarle a un tercero por
    dónde se mueve la carga del cliente, y se ve igual en tema claro y oscuro.

    El dibujo del mundo es opcional (`MARCA_MAPA_FONDO`): sin él quedan la
    retícula y las rutas, que es lo que de verdad se lee.
--}}
<section class="rounded-2xl border border-line bg-panel p-4 sm:p-5">
    <header class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <div>
            <h2 class="text-sm font-semibold text-ink">{{ __('Rutas en curso') }}</h2>
            <p class="mt-0.5 text-xs text-ink-faint">
                {{ __('Posición estimada por fechas de carga y arribo, no rastreo en vivo.') }}
            </p>
        </div>
        <span class="text-xs text-ink-faint">{{ trans_choice(':count ruta|:count rutas', count($rutas)) }}</span>
    </header>

    @if ($rutas === [])
        <p class="rounded-xl border border-dashed border-line px-4 py-10 text-center text-sm text-ink-faint">
            {{ __('No hay rutas que dibujar. Hace falta que los lugares tengan coordenadas.') }}
        </p>
    @else
        <div class="overflow-x-auto">
            <svg viewBox="0 0 {{ RouteMap::ANCHO }} {{ RouteMap::ALTO }}" role="img"
                 class="h-auto w-full min-w-[36rem] rounded-xl"
                 aria-label="{{ __('Rutas en curso') }}">
                <rect width="{{ RouteMap::ANCHO }}" height="{{ RouteMap::ALTO }}" fill="var(--raised)" />

                @if ($fondo)
                    <image href="{{ \Illuminate\Support\Str::startsWith($fondo, ['http://', 'https://']) ? $fondo : asset($fondo) }}"
                           x="0" y="0" width="{{ RouteMap::ANCHO }}" height="{{ RouteMap::ALTO }}"
                           opacity="0.45" preserveAspectRatio="none" />
                @endif

                {{-- Retícula cada 30°: da escala sin competir con las rutas. --}}
                <g stroke="var(--line)" stroke-width="1" opacity="0.6">
                    @for ($lon = -180; $lon <= 180; $lon += 30)
                        @php $x = RouteMap::punto(0, $lon)['x']; @endphp
                        <line x1="{{ $x }}" y1="0" x2="{{ $x }}" y2="{{ RouteMap::ALTO }}" />
                    @endfor
                    @for ($lat = -60; $lat <= 60; $lat += 30)
                        @php $y = RouteMap::punto($lat, 0)['y']; @endphp
                        <line x1="0" y1="{{ $y }}" x2="{{ RouteMap::ANCHO }}" y2="{{ $y }}" />
                    @endfor
                </g>

                {{-- El ecuador, marcado aparte para orientarse de un vistazo. --}}
                <line x1="0" y1="{{ RouteMap::punto(0, 0)['y'] }}" x2="{{ RouteMap::ANCHO }}"
                      y2="{{ RouteMap::punto(0, 0)['y'] }}" stroke="var(--line)" stroke-width="2" />

                @foreach ($rutas as $ruta)
                    @php
                        $curva = "M {$ruta['origen']['x']},{$ruta['origen']['y']} ".
                                 "Q {$ruta['control']['x']},{$ruta['control']['y']} ".
                                 "{$ruta['destino']['x']},{$ruta['destino']['y']}";
                        $titulo = $ruta['booking'].' · '.$ruta['origen']['nombre'].' → '.$ruta['destino']['nombre'].
                                  ' · '.round($ruta['avance'] * 100).'%';
                    @endphp

                    <g>
                        <title>{{ $titulo }}</title>

                        <path d="{{ $curva }}" fill="none" stroke="var(--color-accent-500)"
                              stroke-width="2" stroke-linecap="round" opacity="0.55" />

                        <circle cx="{{ $ruta['origen']['x'] }}" cy="{{ $ruta['origen']['y'] }}" r="4"
                                fill="var(--raised)" stroke="var(--color-accent-600)" stroke-width="2" />
                        <circle cx="{{ $ruta['destino']['x'] }}" cy="{{ $ruta['destino']['y'] }}" r="4"
                                fill="var(--color-accent-600)" />

                        {{-- El medio, en su posición estimada. --}}
                        <circle cx="{{ $ruta['posicion']['x'] }}" cy="{{ $ruta['posicion']['y'] }}" r="9"
                                fill="var(--color-accent-500)" opacity="0.18" />
                        <circle cx="{{ $ruta['posicion']['x'] }}" cy="{{ $ruta['posicion']['y'] }}" r="4.5"
                                fill="var(--color-accent-500)" stroke="var(--raised)" stroke-width="1.5" />
                    </g>
                @endforeach

                {{-- Nombres de los lugares.
                     Sin el dibujo del mundo, unos puntos sobre una retícula no
                     dicen nada; con el nombre al lado el mapa se lee igual. Se
                     agrupan por lugar y no por ruta: varios embarques al mismo
                     puerto escribirían el nombre encima de sí mismo. Los
                     orígenes van a la izquierda porque se amontonan en la
                     costa y las rutas salen hacia la derecha. --}}
                @php
                    $etiquetas = [];

                    foreach ($rutas as $ruta) {
                        foreach ([['origen', 'end', -8], ['destino', 'start', 8]] as [$punta, $anclaje, $desvio]) {
                            $etiquetas[$ruta[$punta]['nombre'].'|'.$anclaje] = [
                                'nombre' => $ruta[$punta]['nombre'],
                                'x' => $ruta[$punta]['x'] + $desvio,
                                'y' => $ruta[$punta]['y'] + 4,
                                'anclaje' => $anclaje,
                            ];
                        }
                    }

                    // Y se apartan los que se pisan. Sin esto, los puertos
                    // cercanos —Rotterdam y Hamburgo están a dos grados, y los
                    // de origen se amontonan todos en la misma costa— escriben
                    // un nombre encima de otro y no se lee ninguno.
                    usort($etiquetas, fn ($a, $b) => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);

                    $colocadas = [];

                    foreach ($etiquetas as $i => $etiqueta) {
                        $intentos = 0;

                        while ($intentos < 8) {
                            $choca = false;

                            foreach ($colocadas as $puesta) {
                                if (abs($puesta['x'] - $etiqueta['x']) < 130 && abs($puesta['y'] - $etiqueta['y']) < 15) {
                                    $choca = true;
                                    break;
                                }
                            }

                            if (! $choca) {
                                break;
                            }

                            $etiqueta['y'] += 16;
                            $intentos++;
                        }

                        $etiquetas[$i] = $etiqueta;
                        $colocadas[] = $etiqueta;
                    }
                @endphp

                @foreach ($etiquetas as $etiqueta)
                    <text x="{{ $etiqueta['x'] }}" y="{{ $etiqueta['y'] }}"
                          text-anchor="{{ $etiqueta['anclaje'] }}"
                          font-size="13" fill="var(--ink-muted)"
                          style="paint-order: stroke; stroke: var(--raised); stroke-width: 3px;">{{ $etiqueta['nombre'] }}</text>
                @endforeach
            </svg>
        </div>

        <ul class="mt-3 grid gap-x-6 gap-y-1 text-xs text-ink-muted sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($rutas as $ruta)
                <li class="flex min-w-0 items-baseline gap-2">
                    <a href="{{ route('operations.bookings.show', $ruta['booking_id']) }}" wire:navigate
                       class="shrink-0 font-medium text-brand hover:underline">{{ $ruta['booking'] }}</a>
                    <span class="truncate" title="{{ $ruta['origen']['nombre'] }} → {{ $ruta['destino']['nombre'] }}">
                        {{ $ruta['origen']['nombre'] }} → {{ $ruta['destino']['nombre'] }}
                    </span>
                    <span class="ml-auto shrink-0 tabular-nums text-ink-faint">{{ round($ruta['avance'] * 100) }}%</span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
