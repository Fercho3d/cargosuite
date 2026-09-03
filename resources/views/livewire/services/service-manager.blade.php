@php
    $money = fn ($v) => number_format((float) $v, 2);
    $esVenta = $this->isSale();
    $esAdmin = auth()->user()?->isAdmin() ?? false;
@endphp

<div class="space-y-4">

    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-ink">{{ __('Servicios y precios') }}</h2>
            <p class="text-sm text-ink-muted">
                {!! __('El precio pactado con cada tercero. Un servicio en <strong>0</strong> es precio abierto: se captura a mano al agregar el concepto.') !!}
            </p>
        </div>

        @if ($esAdmin)
            <button type="button" wire:click="create" class="btn-accent !px-3 !py-1.5 text-xs">{{ __('Agregar') }}</button>
        @endif
    </header>

    {{-- Filtros --}}
    <div class="card p-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <label class="block">
                <span class="field-label text-xs">{{ __('Tipo') }}</span>
                <select wire:model.live="type" class="field-input mt-1 py-1.5 text-sm">
                    @foreach (['1' => __('De venta (cliente)'), '2' => __('De compra (proveedor)')] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected((string) $valor === $type)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ $esVenta ? __('Cliente') : __('Proveedor') }}</span>
                <select wire:model.live="partyId" class="field-input mt-1 py-1.5 text-sm">
                    <option value="">{{ __('Todos') }}</option>
                    @foreach ($terceros as $id => $nombre)
                        <option value="{{ $id }}" @selected((string) $id === $partyId)>{{ $nombre }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Descripción') }}</span>
                <input type="search" wire:model.live.debounce.300ms="search" value="{{ $search }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('Buscar…') }}">
            </label>
        </div>
    </div>

    {{-- Formulario --}}
    @if ($editing !== null)
        <form wire:submit="save" class="card space-y-4 p-5">
            <p class="text-sm font-medium text-ink">{{ $editing === 0 ? __('Nuevo servicio') : __('Editar servicio') }}</p>

            @include('partials.validation-errors')

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <label class="block lg:col-span-2">
                    <span class="field-label">{{ __('Descripción') }}</span>
                    <input type="text" wire:model="form.description" value="{{ $form['description'] ?? '' }}" class="field-input mt-1.5" required>
                    @error('form.description') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="field-label">{{ $esVenta ? __('Cliente') : __('Proveedor') }}</span>
                    <select wire:model="form.party_id" class="field-input mt-1.5" required>
                        <option value="">{{ __('Selecciona') }}</option>
                        @foreach ($terceros as $id => $nombre)
                            <option value="{{ $id }}" @selected((string) $id === (string) ($form['party_id'] ?? ''))>{{ $nombre }}</option>
                        @endforeach
                    </select>
                    @error('form.party_id') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="field-label">{{ __('Tipo de cargo') }}</span>
                    <select wire:model="form.charge_type_id" class="field-input mt-1.5" required>
                        <option value="">{{ __('Selecciona') }}</option>
                        @foreach ($tiposDeCargo as $id => $nombre)
                            <option value="{{ $id }}" @selected((string) $id === (string) ($form['charge_type_id'] ?? ''))>{{ $nombre }}</option>
                        @endforeach
                    </select>
                    @error('form.charge_type_id') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="field-label">
                        Precio <span class="font-normal text-ink-faint">{{ __('(0 = abierto)') }}</span>
                    </span>
                    <input type="number" step="0.0001" min="0" wire:model="form.price" value="{{ $form['price'] ?? '' }}"
                           class="field-input mt-1.5 text-right tabular-nums" required>
                    @error('form.price') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="field-label">{{ __('Divisa') }}</span>
                    <select wire:model="form.account_id" class="field-input mt-1.5" required>
                        <option value="">{{ __('Selecciona') }}</option>
                        @foreach ($divisas as $id => $prefijo)
                            <option value="{{ $id }}" @selected((string) $id === (string) ($form['account_id'] ?? ''))>{{ $prefijo }}</option>
                        @endforeach
                    </select>
                    @error('form.account_id') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                </label>

                @foreach ([['min', __('Precio mínimo')], ['max', __('Precio máximo')]] as [$campo, $etiqueta])
                    <label class="block">
                        <span class="field-label">
                            {{ $etiqueta }} <span class="font-normal text-ink-faint">(referencia)</span>
                        </span>
                        <input type="number" step="0.0001" min="0" wire:model="form.{{ $campo }}" value="{{ $form[$campo] ?? '' }}"
                               class="field-input mt-1.5 text-right tabular-nums">
                        @error('form.'.$campo) <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                    </label>
                @endforeach

                <label class="flex items-end gap-2 pb-2.5 text-sm text-ink-soft">
                    <input type="checkbox" wire:model="form.active" @checked($form['active'] ?? false)
                           class="h-4 w-4 rounded border-line bg-panel text-accent-500 focus:ring-accent-500">
                    {{ __('Activo') }}
                </label>
            </div>

            {{-- Lo que necesita la generación automática del booking --}}
            <div class="rounded-xl border border-line bg-raised/40 p-4">
                <label class="flex items-start gap-2.5 text-sm text-ink-soft">
                    <input type="checkbox" wire:model.live="form.auto_include" @checked($form['auto_include'] ?? false)
                           class="mt-0.5 h-4 w-4 rounded border-line bg-panel text-accent-500 focus:ring-accent-500">
                    <span>
                        {{ __('Incluirlo solo en la factura y los costos del booking') }}
                        <span class="mt-0.5 block text-xs text-ink-faint">
                            {{ __('El booking lo propondrá cuando su ruta coincida con la de aquí abajo. Los campos que dejes vacíos solo empatan con bookings que tampoco los tengan.') }}
                        </span>
                    </span>
                </label>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="block">
                        <span class="field-label">{{ __('Tipo de precio') }}</span>
                        <select wire:model="form.price_type" class="field-input mt-1.5">
                            <option value="">{{ __('Sin definir') }}</option>
                            @foreach ($this->priceTypes() as $id => $etiqueta)
                                <option value="{{ $id }}" @selected((string) $id === (string) ($form['price_type'] ?? ''))>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                        @error('form.price_type') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                    </label>

                    @foreach ([
                        ['loading_port_id', __('Puerto de carga'), $puertosCarga],
                        ['dicharge_port_id', __('Puerto de descarga'), $puertosDescarga],
                        ['pickup_place_id', __('Lugar de recolección'), $lugares],
                        ['final_destination_id', 'Destino final', $destinos],
                        ['container_type_id', __('Tipo de contenedor'), $tiposContenedor],
                    ] as [$campo, $etiqueta, $opciones])
                        <label class="block">
                            <span class="field-label">{{ $etiqueta }}</span>
                            <select wire:model="form.{{ $campo }}" class="field-input mt-1.5">
                                <option value="">{{ __('Cualquiera') }}</option>
                                @foreach ($opciones as $id => $nombre)
                                    <option value="{{ $id }}" @selected((string) $id === (string) ($form[$campo] ?? ''))>{{ $nombre }}</option>
                                @endforeach
                            </select>
                            @error('form.'.$campo) <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                        </label>
                    @endforeach

                    @foreach ([['start_date', __('Vigente desde')], ['end_date', __('Vigente hasta')]] as [$campo, $etiqueta])
                        <label class="block">
                            <span class="field-label">{{ $etiqueta }}</span>
                            <input type="date" wire:model="form.{{ $campo }}" value="{{ $form[$campo] ?? '' }}" class="field-input mt-1.5">
                            @error('form.'.$campo) <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-line pt-4">
                <button type="button" wire:click="cancel" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Cancelar') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="btn-accent !px-3 !py-1.5 text-xs">
                    <x-spinner wire:loading wire:target="save" class="h-3.5 w-3.5" />
                    {{ __('Guardar') }}
                </button>
            </div>
        </form>
    @endif

    {{-- Listado --}}
    <div class="relative rounded-xl border border-line bg-panel">
        <div wire:loading.delay class="absolute inset-0 z-20 rounded-xl bg-panel/75 text-center backdrop-blur-[1px]">
            <span class="mt-14 inline-flex items-center gap-2 rounded-full border border-line bg-panel px-4 py-2 text-sm text-ink-muted shadow-lg">
                <x-spinner class="h-4 w-4 text-brand" /> Actualizando…
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Descripción') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ $esVenta ? __('Cliente') : __('Proveedor') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Tipo de cargo') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Precio') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Estado') }}</th>
                        @if ($esAdmin)
                            <th class="px-4 py-2.5 text-right font-semibold"><span class="sr-only">{{ __('Acciones') }}</span></th>
                        @endif
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    @forelse ($servicios as $servicio)
                        <tr class="transition hover:bg-raised {{ $servicio->active ? '' : 'opacity-60' }}">
                            <td class="max-w-[22rem] px-4 py-2">
                                <p class="truncate text-ink" title="{{ $servicio->description }}">{{ $servicio->description ?: '—' }}</p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-ink-faint">
                                    <span>{{ $servicio->currency ?: '—' }}</span>
                                    @if ($servicio->auto_include)
                                        <span class="badge badge-neutral">{{ __('Auto-incluible') }}</span>
                                        @if ($servicio->end_date)
                                            <span>vigente hasta {{ $servicio->end_date }}</span>
                                        @endif
                                    @endif
                                </p>
                            </td>
                            <td class="max-w-[14rem] truncate px-4 py-2 text-ink-muted">
                                {{ ($esVenta ? $servicio->client_name : $servicio->provider_name) ?: '—' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ $servicio->charge_type_name ?: '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-soft">
                                @if ((float) $servicio->price === 0.0)
                                    <span class="badge badge-warn">{{ __('Abierto') }}</span>
                                @else
                                    {{ $money($servicio->price) }}
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
                                        <button type="button" wire:click="edit({{ $servicio->service_id }})" class="text-brand hover:underline">Editar</button>
                                        <button type="button" wire:click="toggleActive({{ $servicio->service_id }})"
                                                class="text-ink-muted transition hover:text-brand">
                                            {{ $servicio->active ? 'Desactivar' : 'Activar' }}
                                        </button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $esAdmin ? 6 : 5 }}" class="px-4 py-12 text-center text-ink-faint">
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
