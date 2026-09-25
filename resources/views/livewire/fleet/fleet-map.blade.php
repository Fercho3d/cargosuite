@assets
    @vite('resources/js/mapa-flota.js')
@endassets

<div class="space-y-4" wire:poll.30s>
    @if ($embebido)
        <header class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="text-sm font-semibold text-ink">{{ __('Mapa de la flota') }}</h2>
            <a href="{{ route('fleet.map') }}" wire:navigate class="text-xs text-brand hover:underline">{{ __('Abrir el mapa completo') }}</a>
        </header>
    @else
    <header class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-ink">{{ __('Mapa de la flota') }}</h1>
            <p class="mt-0.5 text-sm text-ink-muted">
                {{ __('La última posición que reportó el GPS de cada unidad. Se actualiza sola cada 30 segundos.') }}
                {{ __('Toque una unidad en viaje para ver su ruta.') }}
            </p>
        </div>
        <a href="{{ route('catalogs.show', 'gps') }}" wire:navigate class="text-xs text-brand hover:underline">{{ __('Dispositivos GPS') }}</a>
    </header>

    <div class="flex flex-wrap items-end gap-3 rounded-2xl border border-line bg-panel p-4">
        <label class="block min-w-48 flex-1">
            <span class="field-label">{{ __('Buscar') }}</span>
            <input type="search" wire:model.live.debounce.400ms="buscar" class="field-input mt-1.5"
                   placeholder="{{ __('Unidad, placas, operador o viaje') }}">
        </label>
        <label class="block">
            <span class="field-label">{{ __('Estado') }}</span>
            <select wire:model.live="estado" class="field-input mt-1.5">
                <option value="">{{ __('Todas') }} ({{ $total }})</option>
                <option value="movimiento">{{ __('En movimiento') }} ({{ $cuenta['movimiento'] ?? 0 }})</option>
                <option value="detenida">{{ __('Detenidas') }} ({{ $cuenta['detenida'] ?? 0 }})</option>
                <option value="sin_senal">{{ __('Sin señal') }} ({{ $cuenta['sin_senal'] ?? 0 }})</option>
                <option value="fuera_de_ruta">{{ __('Fuera de ruta') }} ({{ $cuenta['fuera_de_ruta'] ?? 0 }})</option>
            </select>
        </label>
        <label class="flex cursor-pointer items-center gap-2 pb-2 text-sm text-ink">
            <input type="checkbox" wire:model.live="soloEnViaje" class="h-4 w-4 rounded border-line">
            {{ __('Solo en viaje') }}
        </label>
    </div>
    @endif

    @if ($desviadas->isNotEmpty())
        <div class="alert-danger flex flex-wrap items-center gap-x-4 gap-y-1">
            <strong>{{ trans_choice('{1}:n unidad fuera de ruta|[2,*]:n unidades fuera de ruta', $desviadas->count(), ['n' => $desviadas->count()]) }}</strong>
            @foreach ($desviadas as $d)
                <button type="button" wire:click="verRuta({{ $d['id'] }})" class="underline">
                    {{ $d['numero'] }} · {{ $d['viaje'] }} · {{ __('a :km km', ['km' => number_format($d['desvioKm'], 1)]) }}
                </button>
            @endforeach
        </div>
    @endif

    @if ($ruta)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-panel px-4 py-2.5 text-sm">
            <span class="text-ink">
                <span class="font-semibold">{{ __('Ruta de :viaje', ['viaje' => $ruta['viaje']]) }}</span>
                @if ($ruta['paradas'] === [])
                    <span class="text-ink-muted">· {{ __('El origen o el destino no tienen coordenadas en su catálogo.') }}</span>
                @elseif ($ruta['aproximada'])
                    <span class="text-ink-muted">· {{ __('Aproximada (línea recta): el servicio de rutas no respondió.') }}</span>
                @elseif ($ruta['km'])
                    <span class="text-ink-muted">· {{ number_format($ruta['km']) }} km · {{ __(':h h :m min de manejo', ['h' => intdiv((int) $ruta['minutos'], 60), 'm' => (int) $ruta['minutos'] % 60]) }}</span>
                @endif
                <span class="ml-2 inline-flex items-center gap-3 text-xs text-ink-muted">
                    <span><span class="inline-block h-0.5 w-5 bg-[#2563eb] align-middle"></span> {{ __('Planeada') }}</span>
                    <span><span class="inline-block h-1 w-5 bg-[#16a34a] align-middle"></span> {{ __('Recorrido') }}</span>
                </span>
            </span>
            <button type="button" wire:click="quitarRuta" class="btn-ghost !px-3 !py-1 text-xs">{{ __('Quitar ruta') }}</button>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-[1fr_18rem]">
        {{-- El mapa lo maneja Leaflet: Livewire no debe tocar ese nodo. --}}
        <div wire:ignore class="h-[32rem] overflow-hidden rounded-2xl border border-line">
            <div id="mapa-flota" class="h-full w-full"></div>
        </div>

        <ul class="max-h-[32rem] space-y-2 overflow-y-auto">
            @forelse ($puntos as $u)
                <li wire:key="u{{ $u['id'] }}">
                    {{-- Con viaje, tocar la unidad dibuja su ruta; sin viaje, la centra. --}}
                    <button type="button"
                            @if ($u['viaje']) wire:click="verRuta({{ $u['id'] }})" @else x-on:click="$dispatch('enfocar-unidad', { id: {{ $u['id'] }} })" @endif
                            class="w-full rounded-xl border border-line bg-panel px-3 py-2 text-left transition hover:bg-raised">
                        <span class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-ink">{{ $u['numero'] }}</span>
                            <span @class([
                                'rounded px-1.5 py-0.5 text-[10px]',
                                'badge-ok' => $u['estado'] === 'movimiento',
                                'badge-warn' => $u['estado'] === 'detenida',
                                'badge-danger' => $u['estado'] === 'sin_senal',
                            ])>{{ ['movimiento' => __('En movimiento'), 'detenida' => __('Detenida'), 'sin_senal' => __('Sin señal')][$u['estado']] }}</span>
                        </span>
                        @if ($u['fueraDeRuta'])
                            <span class="badge-danger mt-1 inline-block rounded px-1.5 py-0.5 text-[10px]">
                                {{ __('Fuera de ruta') }} · {{ __('a :km km', ['km' => number_format($u['desvioKm'], 1)]) }}
                            </span>
                        @endif
                        <span class="mt-0.5 block text-xs text-ink-muted">
                            {{ $u['velocidad'] }} km/h · {{ $u['senal'] }}
                            @if ($u['viaje'])
                                <br>{{ $u['viaje'] }}{{ $u['operador'] ? ' · '.$u['operador'] : '' }}
                            @endif
                        </span>
                    </button>
                    @if ($u['viaje'])
                        <button type="button" wire:click="verRuta({{ $u['id'] }})"
                                class="mt-1 text-xs text-brand hover:underline {{ ($ruta['unidad'] ?? null) === $u['id'] ? 'font-semibold' : '' }}">{{ __('Ver ruta del viaje') }}</button>
                    @endif
                </li>
            @empty
                <li class="rounded-xl border border-dashed border-line px-3 py-8 text-center text-sm text-ink-faint">
                    {{ __('Ninguna unidad con posición para estos filtros.') }}
                </li>
            @endforelse
        </ul>
    </div>
</div>

@script
<script>
    const colores = { movimiento: '#16a34a', detenida: '#d97706', sin_senal: '#94a3b8', fuera_de_ruta: '#dc2626' };
    const mapa = L.map('mapa-flota', { zoomControl: true }).setView([23.6, -102.5], 5);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        // En pantallas de alta densidad pide el mosaico del siguiente nivel:
        // si no, el texto del mapa sale borroso y enorme.
        detectRetina: true,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(mapa);

    // El mapa nace antes de que la página termine de acomodarse: sin esto,
    // Leaflet se queda con un tamaño viejo y los mosaicos salen estirados.
    setTimeout(() => mapa.invalidateSize(), 150);
    window.addEventListener('resize', () => mapa.invalidateSize());

    const capa = L.layerGroup().addTo(mapa);
    const marcadores = {};
    let primeraVez = true;
    const texto = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    const pinta = (puntos) => {
        capa.clearLayers();
        for (const u of puntos) {
            const icono = L.divIcon({
                className: '',
                html: `<div style="background:${colores[u.fueraDeRuta ? 'fuera_de_ruta' : u.estado]};color:#fff;border:2px solid #fff;border-radius:9999px;padding:2px 7px;font:600 11px system-ui;white-space:nowrap;box-shadow:0 1px 4px rgba(0,0,0,.35)">${u.fueraDeRuta ? '⚠ ' : ''}${texto(u.numero)}</div>`,
                // Sin tamaño fijo: la etiqueta crece con el número de la unidad.
                iconSize: null,
                iconAnchor: [18, 10],
            });
            const viaje = u.viaje ? `<br><a href="${u.viajeUrl}">${texto(u.viaje)}</a>${u.cliente ? ' · ' + texto(u.cliente) : ''}${u.operador ? '<br>' + texto(u.operador) : ''}<br><a href="#" data-ruta="${u.id}">${texto(@js(__('Ver ruta del viaje')))}</a>` : '';
            marcadores[u.id] = L.marker([u.lat, u.lng], { icon: icono })
                .bindPopup(`<strong>${texto(u.numero)}</strong> ${texto(u.placas)}<br>${u.velocidad} km/h · ${texto(u.senal)}${viaje}`)
                .addTo(capa);
        }
        if (primeraVez && puntos.length) {
            mapa.fitBounds(L.latLngBounds(puntos.map((u) => [u.lat, u.lng])), { padding: [40, 40], maxZoom: 11 });
            primeraVez = false;
        }
    };

    pinta($wire.puntos);
    $wire.$watch('puntos', pinta);

    // La ruta del viaje escogido: planeada (azul) y recorrido real (verde).
    const capaRuta = L.layerGroup().addTo(mapa);
    let rutaVista = null;

    const dibujaRuta = (r) => {
        capaRuta.clearLayers();
        if (!r) { rutaVista = null; return; }

        const lineas = [];
        if (r.planeada.length > 1) {
            lineas.push(L.polyline(r.planeada, { color: '#2563eb', weight: 4, opacity: 0.75, dashArray: r.aproximada ? '8 8' : null }).addTo(capaRuta));
        }
        if (r.recorrido.length > 1) {
            lineas.push(L.polyline(r.recorrido, { color: '#16a34a', weight: 5, opacity: 0.9 }).addTo(capaRuta));
        }
        r.paradas.forEach((p, i) => {
            L.circleMarker([p.lat, p.lng], { radius: 7, color: '#1e293b', weight: 2, fillColor: '#fff', fillOpacity: 1 })
                .bindTooltip(`${i + 1}. ${texto(p.nombre)}`, { permanent: true, direction: 'top', offset: [0, -8] })
                .addTo(capaRuta);
        });

        // Encuadra solo al escoger otra ruta: el refresco no debe mover el mapa.
        // Se espera al siguiente cuadro: al aparecer la barra de la ruta, la
        // página se reacomoda y encuadrar antes usaría medidas viejas.
        if (rutaVista !== r.booking && lineas.length) {
            rutaVista = r.booking;
            const caja = L.featureGroup(lineas).getBounds();
            requestAnimationFrame(() => {
                mapa.invalidateSize();
                mapa.fitBounds(caja, { padding: [40, 40] });
            });
        }
    };

    dibujaRuta($wire.ruta);
    $wire.$watch('ruta', dibujaRuta);

    document.getElementById('mapa-flota').addEventListener('click', (e) => {
        const enlace = e.target.closest('[data-ruta]');
        if (enlace) { e.preventDefault(); $wire.verRuta(Number(enlace.dataset.ruta)); }
    });

    window.addEventListener('enfocar-unidad', (e) => {
        const m = marcadores[e.detail.id];
        if (m) { mapa.flyTo(m.getLatLng(), 13); m.openPopup(); }
    });
</script>
@endscript
