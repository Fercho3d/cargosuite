@php
    $money = fn ($v) => number_format((float) $v, 2);
    // El esquema heredado guarda «sin fecha» como `0000-00-00`: se trata como vacía.
    $fecha = fn ($v) => $v && ! str_starts_with((string) $v, '0000-00-00') ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : null;
    $esVenta = $this->isSale();
    $porTipo = $this->filtersByType();
    $esAdmin = auth()->user()?->isSuperAdmin() ?? false;
@endphp

<div class="space-y-4">

    @if ($volver !== '')
        <a href="{{ $volver }}" wire:navigate
           class="inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-ink">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            {{ __('Volver') }}
        </a>
    @endif

    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-ink">{{ __('Servicios y precios') }}</h2>
            <p class="text-sm text-ink-muted">
                {!! __('El precio pactado con cada tercero. Un servicio en <strong>0</strong> es precio abierto: se captura a mano al agregar el concepto.') !!}
            </p>
        </div>

        @if ($esAdmin)
            <a href="{{ route('parties.services.create', array_filter(['tipo' => $type, 'tercero' => $partyId, 'volver' => $this->currentUrl()])) }}" wire:navigate
               class="btn-accent !px-3 !py-1.5 text-xs">{{ __('Agregar') }}</a>
        @endif
    </header>

    {{-- Filtros --}}
    <div class="card p-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <label class="block">
                <span class="field-label text-xs">{{ __('Tipo') }}</span>
                <select wire:model.live="type" class="field-input mt-1 py-1.5 text-sm">
                    @foreach (['todos' => __('Todos'), '1' => __('De venta (cliente)'), '2' => __('De compra (proveedor)')] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected((string) $valor === $type || ($valor === 'todos' && ! $porTipo))>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>

            {{-- Sin tipo no hay de dónde sacar la lista: clientes y proveedores no se mezclan --}}
            @if ($porTipo)
                <label class="block">
                    <span class="field-label text-xs">{{ $esVenta ? __('Cliente') : __('Proveedor') }}</span>
                    <select wire:model.live="partyId" class="field-input mt-1 py-1.5 text-sm">
                        <option value="">{{ __('Todos') }}</option>
                        @foreach ($terceros as $id => $nombre)
                            <option value="{{ $id }}" @selected((string) $id === $partyId)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <label class="block">
                <span class="field-label text-xs">{{ __('Descripción') }}</span>
                <input type="search" wire:model.live.debounce.300ms="search" value="{{ $search }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('Buscar…') }}">
            </label>
        </div>
    </div>

    {{-- Listado --}}
    <div class="relative rounded-xl border border-line bg-panel">
        <div wire:loading.delay class="absolute inset-0 z-20 rounded-xl bg-panel/75 text-center backdrop-blur-[1px]">
            <span class="mt-14 inline-flex items-center gap-2 rounded-full border border-line bg-panel px-4 py-2 text-sm text-ink-muted shadow-lg">
                <x-spinner class="h-4 w-4 text-brand" /> {{ __('Actualizando…') }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2.5 text-right font-semibold">ID</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Descripción') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ ! $porTipo ? __('Cliente o proveedor') : ($esVenta ? __('Cliente') : __('Proveedor')) }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Tipo de cargo') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Precio') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Tipo de precio') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Ruta') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Contenedor') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Vigencia') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Modificado') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Estado') }}</th>
                        @if ($esAdmin)
                            <th class="px-4 py-2.5 text-right font-semibold"><span class="sr-only">{{ __('Acciones') }}</span></th>
                        @endif
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    @forelse ($servicios as $servicio)
                        <tr class="transition hover:bg-raised {{ $servicio->active ? '' : 'opacity-60' }}">
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-faint">{{ $servicio->service_id }}</td>
                            <td class="max-w-[22rem] px-4 py-2">
                                <p class="flex items-center gap-1.5 truncate text-ink" title="{{ $servicio->description }}">
                                    <span class="truncate">{{ $servicio->description ?: '—' }}</span>
                                    @if (! empty($servicio->contract))
                                        <a href="{{ route('parties.services.contract', $servicio->service_id) }}" target="_blank" rel="noopener"
                                           class="shrink-0 text-ink-muted hover:text-brand" title="{{ __('Ver contrato') }}">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M7 3h7l5 5v13H7z"/></svg>
                                            <span class="sr-only">{{ __('Ver contrato') }}</span>
                                        </a>
                                    @endif
                                </p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-ink-faint">
                                    <span>{{ $servicio->currency ?: '—' }}</span>
                                    @if ($servicio->auto_include)
                                        <span class="badge badge-neutral">{{ __('Auto-incluible') }}</span>
                                    @endif
                                </p>
                            </td>
                            <td class="max-w-[14rem] truncate px-4 py-2 text-ink-muted">
                                {{ (! $porTipo ? ($servicio->client_name ?: $servicio->provider_name) : ($esVenta ? $servicio->client_name : $servicio->provider_name)) ?: '—' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ $servicio->charge_type_name ?: '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-soft">
                                @if ((float) $servicio->price === 0.0)
                                    <span class="badge badge-warn">{{ __('Abierto') }}</span>
                                @else
                                    {{ $money($servicio->price) }}
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ $tiposDePrecio[$servicio->price_type] ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs text-ink-muted">
                                @if ($servicio->pol || $servicio->pod)
                                    <p class="whitespace-nowrap">{{ $servicio->pol ?: '…' }} → {{ $servicio->pod ?: '…' }}</p>
                                @endif
                                @if ($servicio->pickup)
                                    <p class="whitespace-nowrap">{{ __('Recolección') }}: {{ $servicio->pickup }}</p>
                                @endif
                                @if ($servicio->destination)
                                    <p class="whitespace-nowrap">{{ __('Destino') }}: {{ $servicio->destination }}</p>
                                @endif
                                @if (! $servicio->pol && ! $servicio->pod && ! $servicio->pickup && ! $servicio->destination)
                                    —
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ $servicio->container ?: '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-xs text-ink-muted">
                                @if ($servicio->start_date || $servicio->end_date)
                                    {{ $fecha($servicio->start_date) ?? '…' }} – {{ $fecha($servicio->end_date) ?? '…' }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-xs text-ink-muted">
                                @if ($servicio->modified_by_name || $servicio->modified_at)
                                    {{ $servicio->modified_by_name ?: '—' }}
                                    <span class="text-ink-faint">{{ $fecha($servicio->modified_at) }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-2">
                                <span class="badge {{ $servicio->active ? 'badge-ok' : 'badge-neutral' }}">
                                    {{ $servicio->active ? __('Activo') : __('Inactivo') }}
                                </span>
                            </td>
                            @if ($esAdmin)
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    <div class="flex justify-end gap-3 text-xs">
                                        <a href="{{ route('parties.services.edit', [$servicio->service_id, 'volver' => $this->currentUrl()]) }}" wire:navigate class="text-brand hover:underline">{{ __('Editar') }}</a>
                                        <button type="button" wire:click="toggleActive({{ $servicio->service_id }})"
                                                class="text-ink-muted transition hover:text-brand">
                                            {{ $servicio->active ? __('Desactivar') : __('Activar') }}
                                        </button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $esAdmin ? 12 : 11 }}" class="px-4 py-12 text-center text-ink-faint">
                                {{ __('No hay servicios con estos filtros.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>{{ $servicios->links() }}</div>
</div>
