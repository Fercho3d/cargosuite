@php
    $dinero = fn ($v) => $v === null ? '—' : '$'.number_format((float) $v, 2);
    $pct = fn ($v) => $v === null ? '—' : number_format($v, 1).' %';
@endphp

<div class="space-y-4">
    @include('partials.servicios-tabs')

    <header class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-ink">{{ __('Configuración de rutas') }}</h1>
            <p class="mt-0.5 text-sm text-ink-muted">
                {{ __('Cada ruta con sus kilómetros, lo que se cobra, lo que cuesta y el margen. Los precios guardan su historial.') }}
            </p>
        </div>
        <button wire:click="$toggle('creando')"
                class="rounded-lg bg-accent-500 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-accent-600">
            {{ __('Nueva ruta') }}
        </button>
    </header>

    @include('partials.validation-errors')

    @if (session('status'))
        <div class="alert-ok rounded-lg px-3 py-2 text-sm">{{ session('status') }}</div>
    @endif

    @if ($creando)
        <div class="rounded-2xl border border-line bg-panel p-4">
            <div class="grid gap-3 sm:grid-cols-6">
                <label class="block sm:col-span-2">
                    <span class="field-label">{{ __('Origen') }}</span>
                    <select wire:model="origen" class="field-input mt-1.5">
                        <option value="">{{ __('Elige…') }}</option>
                        @foreach ($origenes as $id => $nombre)
                            <option value="{{ $id }}" @selected((string) $id === $origen)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block sm:col-span-2">
                    <span class="field-label">{{ __('Destino') }}</span>
                    <select wire:model="destino" class="field-input mt-1.5">
                        <option value="">{{ __('Elige…') }}</option>
                        @foreach ($destinos as $id => $nombre)
                            <option value="{{ $id }}" @selected((string) $id === $destino)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="field-label">km</span>
                    <input type="number" step="0.1" wire:model="km" value="{{ $km }}" class="field-input mt-1.5">
                </label>
                <label class="block">
                    <span class="field-label">{{ __('Horas') }}</span>
                    <input type="number" step="0.1" wire:model="horas" value="{{ $horas }}" class="field-input mt-1.5">
                </label>
                <label class="block">
                    <span class="field-label">{{ __('Rendimiento (km/L)') }}</span>
                    <input type="number" step="0.01" wire:model="rendimiento" value="{{ $rendimiento }}" class="field-input mt-1.5">
                </label>
                <div class="flex items-end">
                    <x-submit-button wire:click="crear">{{ __('Crear') }}</x-submit-button>
                </div>
            </div>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-[1fr_17rem]">
        <div class="overflow-x-auto rounded-2xl border border-line bg-panel">
            <table class="w-full min-w-[52rem] text-sm">
                <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Ruta') }}</th>
                        <th class="px-3 py-2.5 text-right font-semibold">km</th>
                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('Tarifa') }}</th>
                        <th class="px-3 py-2.5 text-right font-semibold" title="{{ __('Diésel del día más los costos de la ruta') }}">{{ __('Costo unidad propia') }}</th>
                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('Margen') }}</th>
                        <th class="px-3 py-2.5 text-right font-semibold" title="{{ __('El subcontratista más barato') }}">{{ __('Subcontrato') }}</th>
                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('Margen') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($rutas as $r)
                        <tr class="transition hover:bg-raised {{ $r->activo ? '' : 'opacity-50' }}">
                            <td class="px-3 py-2">
                                <a href="{{ route('rutas.show', $r->ruta_id) }}" wire:navigate class="font-medium text-brand hover:underline">
                                    {{ $r->origen }} → {{ $r->destino }}
                                </a>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-ink-muted">{{ number_format((float) $r->km) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-ink">{{ $dinero($r->resumen['venta'] ?: null) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-ink-muted">{{ $dinero($r->resumen['propio']) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums {{ ($r->resumen['margenPropio'] ?? 0) < 0 ? 'text-(--danger-ink)' : 'text-ink' }}">{{ $pct($r->resumen['margenPropio']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-ink-muted">{{ $dinero($r->resumen['subcontrato']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums {{ ($r->resumen['margenSubcontrato'] ?? 0) < 0 ? 'text-(--danger-ink)' : 'text-ink-soft' }}">{{ $pct($r->resumen['margenSubcontrato']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-12 text-center text-ink-faint">{{ __('No hay rutas. Crea la primera con «Nueva ruta».') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- El diésel del día: de él sale el costo de cada ruta con unidad propia. --}}
        <aside class="rounded-2xl border border-line bg-panel p-4">
            <p class="text-sm font-semibold text-ink">{{ __('Precio del diésel') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-ink">
                {{ $diesel ? '$'.number_format((float) $diesel->precio, 2) : '—' }}
                <span class="text-xs font-normal text-ink-faint">/ L</span>
            </p>
            <p class="text-xs {{ $diesel && $diesel->fecha === now()->toDateString() ? 'text-ink-faint' : 'text-(--danger-ink)' }}">
                @if ($diesel === null)
                    {{ __('Sin capturar: los costos con unidad propia no se pueden calcular.') }}
                @elseif ($diesel->fecha === now()->toDateString())
                    {{ __('Capturado hoy') }}
                @else
                    {{ __('Último capturado el :fecha: falta el de hoy.', ['fecha' => \Illuminate\Support\Carbon::parse($diesel->fecha)->format('d/m/Y')]) }}
                @endif
            </p>

            <div class="mt-3 grid grid-cols-2 gap-2">
                <input type="date" wire:model="dieselFecha" value="{{ $dieselFecha }}" class="field-input !py-1.5 text-sm">
                <input type="number" step="0.01" wire:model="dieselPrecio" value="{{ $dieselPrecio }}" placeholder="0.00" class="field-input !py-1.5 text-sm">
            </div>
            <button wire:click="guardarDiesel"
                    class="mt-2 w-full rounded-lg border border-line px-3 py-1.5 text-sm text-ink-soft transition hover:bg-raised">
                {{ __('Guardar precio') }}
            </button>

            @if ($historialDiesel->isNotEmpty())
                <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-ink-faint">{{ __('Historial') }}</p>
                <ul class="mt-1 divide-y divide-line text-sm">
                    @foreach ($historialDiesel as $h)
                        <li class="flex justify-between py-1">
                            <span class="text-ink-muted">{{ \Illuminate\Support\Carbon::parse($h->fecha)->format('d/m/Y') }}</span>
                            <span class="tabular-nums text-ink">${{ number_format((float) $h->precio, 2) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </aside>
    </div>
</div>
