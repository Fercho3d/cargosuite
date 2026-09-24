@assets
    @vite('resources/js/mapa-flota.js')
@endassets

<div class="space-y-4" wire:poll.30s>
    <header class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-ink">{{ __('Mapa de la flota') }}</h1>
            <p class="mt-0.5 text-sm text-ink-muted">
                {{ __('La última posición que reportó el GPS de cada unidad. Se actualiza sola cada 30 segundos.') }}
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
            </select>
        </label>
        <label class="flex cursor-pointer items-center gap-2 pb-2 text-sm text-ink">
            <input type="checkbox" wire:model.live="soloEnViaje" class="h-4 w-4 rounded border-line">
            {{ __('Solo en viaje') }}
        </label>
    </div>

    <div class="grid gap-4 lg:grid-cols-[1fr_18rem]">
        {{-- El mapa lo maneja Leaflet: Livewire no debe tocar ese nodo. --}}
        <div wire:ignore class="h-[32rem] overflow-hidden rounded-2xl border border-line">
            <div id="mapa-flota" class="h-full w-full"></div>
        </div>

        <ul class="max-h-[32rem] space-y-2 overflow-y-auto">
            @forelse ($puntos as $u)
                <li wire:key="u{{ $u['id'] }}">
                    <button type="button" x-on:click="$dispatch('enfocar-unidad', { id: {{ $u['id'] }} })"
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
                        <span class="mt-0.5 block text-xs text-ink-muted">
                            {{ $u['velocidad'] }} km/h · {{ $u['senal'] }}
                            @if ($u['viaje'])
                                <br>{{ $u['viaje'] }}{{ $u['operador'] ? ' · '.$u['operador'] : '' }}
                            @endif
                        </span>
                    </button>
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
    const colores = { movimiento: '#16a34a', detenida: '#d97706', sin_senal: '#94a3b8' };
    const mapa = L.map('mapa-flota', { zoomControl: true }).setView([23.6, -102.5], 5);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(mapa);

    const capa = L.layerGroup().addTo(mapa);
    const marcadores = {};
    let primeraVez = true;
    const texto = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    const pinta = (puntos) => {
        capa.clearLayers();
        for (const u of puntos) {
            const icono = L.divIcon({
                className: '',
                html: `<div style="background:${colores[u.estado]};color:#fff;border:2px solid #fff;border-radius:9999px;padding:2px 7px;font:600 11px system-ui;white-space:nowrap;box-shadow:0 1px 4px rgba(0,0,0,.35)">${texto(u.numero)}</div>`,
                // Sin tamaño fijo: la etiqueta crece con el número de la unidad.
                iconSize: null,
                iconAnchor: [18, 10],
            });
            const viaje = u.viaje ? `<br><a href="${u.viajeUrl}">${texto(u.viaje)}</a>${u.cliente ? ' · ' + texto(u.cliente) : ''}${u.operador ? '<br>' + texto(u.operador) : ''}` : '';
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

    window.addEventListener('enfocar-unidad', (e) => {
        const m = marcadores[e.detail.id];
        if (m) { mapa.flyTo(m.getLatLng(), 13); m.openPopup(); }
    });
</script>
@endscript
