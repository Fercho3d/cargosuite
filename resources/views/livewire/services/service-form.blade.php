@php
    $esVenta = $this->isSale();
    $ruta = $this->routeFields();
@endphp

<div class="mx-auto max-w-5xl space-y-4">

    {{-- Regreso a la lista de servicios, con su filtro --}}
    <a href="{{ $volver }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-ink">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        {{ __('Servicios y precios') }}
    </a>

    <form wire:submit="save" class="card space-y-4 p-5 sm:p-6">
        <h2 class="text-xl font-semibold text-ink">{{ $titulo }}</h2>

        @include('partials.validation-errors')

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @if ($serviceId === null)
                <label class="block">
                    <span class="field-label">{{ __('Tipo') }}</span>
                    <select wire:model.live="type" class="field-input mt-1.5">
                        @foreach (['1' => __('De venta (cliente)'), '2' => __('De compra (proveedor)')] as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected((string) $valor === $type)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <label class="block lg:col-span-2">
                <span class="field-label">{{ __('Descripción') }}</span>
                <input type="text" wire:model="form.description" value="{{ $form['description'] ?? '' }}" class="field-input mt-1.5" required>
                @error('form.description') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="field-label">{{ $esVenta ? __('Cliente') : __('Proveedor') }}</span>
                {{-- En vivo: el tipo del proveedor decide qué campos de ruta se enseñan --}}
                <select wire:model.live="form.party_id" class="field-input mt-1.5" required>
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
                    {{ __('Precio') }} <span class="font-normal text-ink-faint">{{ __('(0 = abierto)') }}</span>
                </span>
                <input type="text" inputmode="decimal" x-data="campoImporte" wire:model="form.price" value="{{ $form['price'] ?? '' }}"
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
                        {{ $etiqueta }} <span class="font-normal text-ink-faint">{{ __('(referencia)') }}</span>
                    </span>
                    <input type="text" inputmode="decimal" x-data="campoImporte" wire:model="form.{{ $campo }}" value="{{ $form[$campo] ?? '' }}"
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

                {{-- La ruta que aplica según el tercero: todo al cliente y a la naviera, POL y
                     recolección al transportista, nada al agente aduanal (como en Yii2). --}}
                @if (! $esVenta && $ruta === [])
                    <p class="text-xs text-ink-faint sm:col-span-2 lg:col-span-3">
                        {{ ($form['party_id'] ?? '') === ''
                            ? __('Elige el proveedor: los campos de ruta dependen de su tipo.')
                            : __('Un agente aduanal no lleva ruta.') }}
                    </p>
                @endif
                @foreach ([
                    ['loading_port_id', __('Puerto de carga'), $puertosCarga],
                    ['dicharge_port_id', __('Puerto de descarga'), $puertosDescarga],
                    ['pickup_place_id', __('Lugar de recolección'), $lugares],
                    ['final_destination_id', __('Destino final'), $destinos],
                    ['container_type_id', __('Tipo de contenedor'), $tiposContenedor],
                ] as [$campo, $etiqueta, $opciones])
                    @continue(! in_array($campo, $ruta, true))
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

        {{-- Contrato en PDF: el mismo archivo por servicio que subía el original --}}
        <div class="rounded-xl border border-line bg-raised/40 p-4">
            <p class="text-sm font-medium text-ink">{{ __('Contrato') }}</p>
            <p class="mt-0.5 text-xs text-ink-faint">{{ __('Un PDF de hasta 5 MB. Subir otro reemplaza al anterior.') }}</p>

            <div class="mt-3 flex flex-wrap items-center gap-4">
                @if ($this->contractUrl())
                    <a href="{{ $this->contractUrl() }}" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1.5 text-sm text-brand hover:underline">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M7 3h7l5 5v13H7z"/></svg>
                        {{ __('Ver contrato') }} <span class="text-ink-faint">({{ $contractName }})</span>
                    </a>
                @endif

                <label class="block text-sm text-ink-soft">
                    <input type="file" wire:model="contract" accept="application/pdf" class="text-xs">
                    <span wire:loading wire:target="contract" class="ml-2 text-xs text-ink-faint">{{ __('Subiendo…') }}</span>
                </label>
            </div>
            @error('contract') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
        </div>

        <div class="flex flex-wrap justify-end gap-3 border-t border-line pt-4">
            <a href="{{ $volver }}" wire:navigate class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Cancelar') }}</a>
            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="btn-accent !px-3 !py-1.5 text-xs">
                <x-spinner wire:loading wire:target="save" class="h-3.5 w-3.5" />
                {{ __('Guardar') }}
            </button>
        </div>
    </form>
</div>
