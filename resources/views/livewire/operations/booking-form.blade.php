@php
    // Cada grupo de campos: etiqueta, propiedad, tipo y opciones.
    $grupos = [
        'Identificación' => [
            [__('Número de booking'), 'bookingNumber', 'text', null, true],
            [__('HB'), 'hb', 'text', null, false],
            [__('Referencia del cliente'), 'customerReference', 'text', null, false],
            [__('Cliente'), 'clientId', 'select', $clientes, true],
            [__('Tipo de booking'), 'bookingType', 'select', \App\Models\Core\Booking::typeLabels(), false],
        ],
        'Transporte' => [
            [__('Buque'), 'vesselId', 'select', $buques, true],
            [__('Naviera'), 'carrierId', 'select', $navieras, false],
            [__('Transportista'), 'transportId', 'select', $transportistas, false],
            [__('Agente aduanal'), 'brokerId', 'select', $agentes, false],
        ],
        'Ruta' => [
            [__('Lugar de recolección'), 'pickupPlace', 'select', $lugares, true],
            [__('Puerto de carga'), 'loadingPort', 'select', $puertosCarga, true],
            [__('Fecha de carga'), 'loadingDate', 'date', null, true],
            [__('Puerto de descarga'), 'dischargePort', 'select', $puertosDescarga, true],
            [__('Fecha de arribo'), 'arrivalDate', 'date', null, true],
            [__('Destino final'), 'finalDestination', 'select', $destinos, false],
        ],
        'Flota' => [
            [__('Operador'), 'operadorId', 'select', $operadores, false],
            [__('Tractor'), 'unidadId', 'select', $tractores, false],
            [__('Caja'), 'cajaId', 'select', $cajas, false],
        ],
        'Carga' => [
            [__('Tipo de contenedor'), 'containerType', 'select', $tiposContenedor, false],
            [__('Mercancía'), 'commodity', 'text', null, false],
            [__('Temperatura'), 'setPoint', 'text', null, false],
        ],
    ];

    // Fuera lo que esta instalación no pide, y fuera el grupo que se quede sin
    // un solo campo: un «Transporte» vacío se vería como un error.
    $grupos = collect($grupos)
        ->map(fn (array $campos) => array_values(array_filter(
            $campos,
            fn (array $campo) => \App\Support\Expediente::visible($campo[1]),
        )))
        ->filter(fn (array $campos) => $campos !== [])
        ->all();

    $verNotas = \App\Support\Expediente::visible('remarks');
@endphp

<div class="mx-auto max-w-4xl space-y-4">

    <a href="{{ $bookingId ? route('operations.bookings.show', $bookingId) : route('operations.bookings') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-ink">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        {{ $bookingId ? __('Volver al booking') : __('Volver a bookings') }}
    </a>

    <form wire:submit="save" class="card space-y-6 p-5 sm:p-6">
        <header>
            <h2 class="text-lg font-semibold text-ink">{{ $this->titulo() }}</h2>
            @if ($bookingId)
                <p class="mt-0.5 text-sm text-ink-muted">{{ $bookingNumber }}</p>
            @else
                {{-- Nace como borrador: la confirmación al cliente sale al
                     confirmarlo desde el detalle, ya con sus contenedores. --}}
                <p class="mt-0.5 text-sm text-ink-muted">
                    {{ $esCotizacion
                        ? __('Se guarda como borrador; una cotización no le manda confirmación al cliente.')
                        : __('Se guarda como borrador. Los contenedores se capturan en el detalle y ahí se confirma para avisar al cliente.') }}
                </p>
            @endif
        </header>

        @if ($locked)
            <p class="flex items-start gap-2 rounded-lg border border-line bg-raised px-3 py-2 text-xs text-ink-muted">
                <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <rect x="5" y="11" width="14" height="9" rx="2"/><path stroke-linecap="round" d="M8 11V8a4 4 0 0 1 8 0v3"/>
                </svg>
                <span>{{ __('Este booking está cerrado: operación lo dio por terminado y su facturación quedó fija.') }}</span>
            </p>
        @endif

        @include('partials.validation-errors')

        @foreach ($grupos as $titulo => $campos)
            <fieldset class="space-y-4" @disabled($locked)>
                <legend class="text-xs font-semibold uppercase tracking-wide text-ink-faint">{{ $titulo }}</legend>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($campos as [$etiqueta, $propiedad, $tipo, $opciones, $obligatorio])
                        <label class="block">
                            <span class="field-label">
                                {{ $etiqueta }}
                                @if ($obligatorio) <span class="text-brand">*</span> @endif
                            </span>

                            @if ($tipo === 'select')
                                <select wire:model="{{ $propiedad }}" @disabled($locked)
                                        class="field-input mt-1.5" @required($obligatorio)>
                                    <option value="">{{ $obligatorio ? __('Selecciona') : __('Sin especificar') }}</option>
                                    @foreach ($opciones as $id => $nombre)
                                        <option value="{{ $id }}" @selected((string) $id === (string) $$propiedad)>{{ $nombre }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="{{ $tipo }}" wire:model="{{ $propiedad }}" value="{{ $$propiedad }}"
                                       @disabled($locked) class="field-input mt-1.5" @required($obligatorio)>
                            @endif

                            @error($propiedad) <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                        </label>

                        {{-- Alta rápida de buque: cambian de nombre y de servicio seguido,
                             y detener la captura para ir al catálogo no tiene sentido. --}}
                        @if ($propiedad === 'vesselId')
                            <label class="block">
                                <span class="field-label">
                                    {{ __('…o un buque nuevo') }}
                                    <span class="font-normal text-ink-faint">{{ __('(se da de alta al guardar)') }}</span>
                                </span>
                                <input type="text" wire:model.live="newVessel" value="{{ $newVessel }}"
                                       @disabled($locked) class="field-input mt-1.5" placeholder="{{ __('Nombre del buque') }}">
                                @error('newVessel') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                            </label>
                        @endif
                    @endforeach
                </div>
            </fieldset>
        @endforeach

        {{-- Campos propios de esta instalación. Se definen en
             /catalogos/campos-expediente y se guardan como filas, no como
             columnas: por eso van en el arreglo `propios` y no en propiedades. --}}
        @foreach (\App\Support\Expediente::propios()->groupBy('grupo') as $titulo => $campos)
            <fieldset class="space-y-4" @disabled($locked)>
                <legend class="text-xs font-semibold uppercase tracking-wide text-ink-faint">{{ $titulo }}</legend>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($campos as $campo)
                        @php $valor = $propios[$campo->clave] ?? null; @endphp
                        <label class="block">
                            <span class="field-label">
                                {{ $campo->etiqueta }}
                                @if ($campo->obligatorio) <span class="text-brand">*</span> @endif
                            </span>

                            @if ($campo->tipo === 'select')
                                <select wire:model="propios.{{ $campo->clave }}" @disabled($locked)
                                        class="field-input mt-1.5" @required($campo->obligatorio)>
                                    <option value="">{{ __('Sin especificar') }}</option>
                                    @foreach ($campo->opciones as $opcion)
                                        <option value="{{ $opcion }}" @selected((string) $valor === (string) $opcion)>{{ $opcion }}</option>
                                    @endforeach
                                </select>
                            @elseif ($campo->tipo === 'boolean')
                                <input type="checkbox" wire:model="propios.{{ $campo->clave }}" value="1"
                                       @checked($valor) @disabled($locked) class="mt-2 h-4 w-4 rounded border-line">
                            @else
                                {{-- El `value` va también desde el servidor: con el JS
                                     de Livewire caído, un campo vacío guardaría nulo. --}}
                                <input type="{{ $campo->tipo === 'number' ? 'number' : ($campo->tipo === 'date' ? 'date' : 'text') }}"
                                       wire:model="propios.{{ $campo->clave }}" value="{{ $valor }}"
                                       @disabled($locked) class="field-input mt-1.5" @required($campo->obligatorio)>
                            @endif

                            @error('propios.'.$campo->clave) <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endforeach

        @if ($verNotas)
        <fieldset class="space-y-2" @disabled($locked)>
            <legend class="text-xs font-semibold uppercase tracking-wide text-ink-faint">{{ __('Notas') }}</legend>
            <textarea wire:model="remarks" rows="3" @disabled($locked) class="field-input">{{ $remarks }}</textarea>
        </fieldset>
        @endif

        <footer class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-4">
            <a href="{{ $bookingId ? route('operations.bookings.show', $bookingId) : route('operations.bookings') }}"
               wire:navigate class="btn-ghost">{{ __('Cancelar') }}</a>
            <button type="submit" wire:loading.attr="disabled" wire:target="save" @disabled($locked) class="btn-accent">
                <x-spinner wire:loading wire:target="save" class="h-4 w-4" />
                {{ $bookingId ? __('Guardar cambios') : ($esCotizacion ? __('Crear cotización') : __('Crear booking')) }}
            </button>
        </footer>
    </form>
</div>
