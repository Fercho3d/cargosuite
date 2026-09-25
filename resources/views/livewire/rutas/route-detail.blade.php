@php
    $dinero = fn ($v) => $v === null ? '—' : '$'.number_format((float) $v, 2);
    $fechaCorta = fn ($v) => \Illuminate\Support\Carbon::parse($v)->format('d/m/Y');
    $secciones = [
        'venta' => [__('Venta al cliente'), __('Sin cliente es el precio general; con cliente, su tarifa especial.')],
        'costo' => [__('Costos con unidad propia'), __('Además del diésel: casetas, maniobras, viáticos…')],
        'subcontrato' => [__('Subcontratistas'), __('Lo que cobra un transportista externo por hacer la ruta.')],
    ];
@endphp

<div class="space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('rutas.index') }}" wire:navigate class="text-xs text-brand hover:underline">← {{ __('Configuración de rutas') }}</a>
            <h1 class="mt-1 text-lg font-semibold text-ink">{{ $ruta->origen }} → {{ $ruta->destino }}</h1>
        </div>
        <label class="flex items-center gap-2 text-sm text-ink-muted">
            {{ __('Calcular al') }}
            <input type="date" wire:model.live="fecha" value="{{ $fecha }}" class="field-input !w-40 !py-1 text-sm">
        </label>
    </header>

    @include('partials.validation-errors')

    @if (session('status'))
        <div class="alert-ok rounded-lg px-3 py-2 text-sm">{{ session('status') }}</div>
    @endif

    {{-- Resumen: lo que deja la ruta en la fecha elegida. --}}
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            [__('Tarifa general'), $dinero($resumen['venta'] ?: null), null],
            [__('Costo con unidad propia'), $dinero($resumen['propio']), $resumen['margenPropio']],
            [__('Subcontrato más barato'), $dinero($resumen['subcontrato']), $resumen['margenSubcontrato']],
            [__('Diésel del viaje'), $dinero($resumen['diesel']), null],
        ] as [$etiqueta, $valor, $margen])
            <div class="card p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-ink-faint">{{ $etiqueta }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-ink">{{ $valor }}</p>
                @if ($margen !== null)
                    <p class="text-xs {{ $margen < 0 ? 'text-(--danger-ink)' : 'text-ink-muted' }}">{{ __('Margen') }} {{ number_format($margen, 1) }} %</p>
                @endif
            </div>
        @endforeach
    </section>

    <p class="text-xs text-ink-faint">
        @if ($diesel && $litros)
            {{ __('Diésel: :km km ÷ :rend km/L = :lt L × $:pl (precio del :fc).', [
                'km' => number_format((float) $ruta->km), 'rend' => (float) $ruta->rendimiento,
                'lt' => number_format($litros, 1), 'pl' => number_format((float) $diesel->precio, 2),
                'fc' => $fechaCorta($diesel->fecha),
            ]) }}
        @else
            {{ __('Sin precio del diésel capturado para esa fecha.') }}
        @endif
    </p>

    {{-- Datos de la ruta --}}
    <section class="rounded-2xl border border-line bg-panel p-4">
        <div class="grid gap-3 sm:grid-cols-5">
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
            <label class="flex items-end gap-2 pb-2 text-sm text-ink">
                <input type="checkbox" wire:model="activo" @checked($activo) class="h-4 w-4 rounded border-line">
                {{ __('Activa') }}
            </label>
            <div class="flex items-end">
                <button wire:click="guardarRuta" class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-soft transition hover:bg-raised">{{ __('Guardar ruta') }}</button>
            </div>
        </div>
    </section>

    {{-- Precios por sección, con su historial --}}
    @foreach ($secciones as $tipoSeccion => [$titulo, $ayuda])
        <section class="overflow-x-auto rounded-2xl border border-line bg-panel">
            <div class="border-b border-line px-4 py-3">
                <h2 class="text-sm font-semibold text-ink">{{ $titulo }}</h2>
                <p class="text-xs text-ink-muted">{{ $ayuda }}</p>
            </div>
            <table class="w-full min-w-[40rem] text-sm">
                <tbody class="divide-y divide-line">
                    @forelse ($tarifas[$tipoSeccion] ?? [] as $t)
                        <tr class="{{ $t->vigente ? '' : 'text-ink-faint' }}">
                            <td class="px-4 py-2">
                                <span class="{{ $t->vigente ? 'font-medium text-ink' : '' }}">{{ $t->concepto }}</span>
                                @if ($t->quien)
                                    <span class="ml-1 text-xs text-ink-muted">· {{ $t->quien }}</span>
                                @endif
                                {{-- Sin tipo de cargo no se puede facturar ni pagar: la generación lo omite. --}}
                                @if ($t->vigente && $t->tipo !== 'costo' && $t->charge_type_id === null)
                                    <span class="ml-1 text-xs text-(--danger-ink)">· {{ __('sin tipo de cargo: no se factura') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-xs">
                                @if ($t->vigente)
                                    <span class="badge-ok rounded px-2 py-0.5">{{ __('Vigente') }}</span>
                                @elseif ($t->futura)
                                    <span class="badge-warn rounded px-2 py-0.5">{{ __('Programado') }}</span>
                                @else
                                    {{ __('Historial') }}
                                @endif
                                <span class="ml-1">{{ __('desde :fecha', ['fecha' => $fechaCorta($t->vigente_desde)]) }}</span>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums {{ $t->vigente ? 'font-semibold text-ink' : '' }}">{{ $dinero($t->precio) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right text-xs">
                                @if ($t->vigente)
                                    <button wire:click="cambiarPrecio({{ $t->tarifa_id }})" class="text-brand hover:underline">{{ __('Cambiar precio') }}</button>
                                @endif
                                <button wire:click="borrarPrecio({{ $t->tarifa_id }})"
                                        wire:confirm="{{ __('¿Borrar este precio? Solo para uno capturado por error: para cambiarlo, captura uno nuevo.') }}"
                                        class="ml-2 text-ink-faint hover:text-brand">{{ __('Borrar') }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-4 text-sm text-ink-faint">{{ __('Sin precios.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @endforeach

    {{-- Precio nuevo --}}
    <section class="rounded-2xl border border-line bg-panel p-4">
        <p class="text-sm font-medium text-ink">{{ __('Precio nuevo') }}</p>
        <p class="mt-0.5 text-xs text-ink-muted">{{ __('Para cambiar un precio captura uno nuevo con la fecha desde la que vale: el anterior queda en el historial.') }}</p>
        <div class="mt-3 grid gap-3 sm:grid-cols-7">
            <label class="block">
                <span class="field-label">{{ __('Tipo') }}</span>
                <select wire:model.live="tipo" class="field-input mt-1.5">
                    <option value="venta" @selected($tipo === 'venta')>{{ __('Venta') }}</option>
                    <option value="costo" @selected($tipo === 'costo')>{{ __('Costo') }}</option>
                    <option value="subcontrato" @selected($tipo === 'subcontrato')>{{ __('Subcontrato') }}</option>
                </select>
            </label>
            <label class="block sm:col-span-2">
                <span class="field-label">{{ __('Concepto') }}</span>
                <input type="text" wire:model="concepto" value="{{ $concepto }}" list="conceptos-ruta" class="field-input mt-1.5"
                       placeholder="{{ __('Flete, maniobras, casetas…') }}">
                <datalist id="conceptos-ruta">
                    @foreach ([__('Flete'), __('Maniobras'), __('Casetas'), __('Viáticos'), __('Custodia'), __('Flete subcontratado')] as $sugerido)
                        <option value="{{ $sugerido }}"></option>
                    @endforeach
                </datalist>
            </label>
            @if ($tipo === 'venta')
                <label class="block">
                    <span class="field-label">{{ __('Cliente') }}</span>
                    <select wire:model="cliente" class="field-input mt-1.5">
                        <option value="">{{ __('General (todos)') }}</option>
                        @foreach ($clientes as $id => $nombre)
                            <option value="{{ $id }}" @selected((string) $id === $cliente)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </label>
            @else
                <label class="block">
                    <span class="field-label">{{ __('Proveedor') }}</span>
                    <select wire:model="proveedor" class="field-input mt-1.5">
                        <option value="">{{ $tipo === 'subcontrato' ? __('Elige…') : __('Ninguno') }}</option>
                        @foreach ($proveedores as $id => $nombre)
                            <option value="{{ $id }}" @selected((string) $id === $proveedor)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label class="block">
                <span class="field-label">{{ __('Tipo de cargo') }}</span>
                <select wire:model="tipoCargo" class="field-input mt-1.5">
                    <option value="">{{ $tipo === 'costo' ? __('Ninguno') : __('Elige…') }}</option>
                    @foreach ($tiposCargo as $id => $nombre)
                        <option value="{{ $id }}" @selected((string) $id === $tipoCargo)>{{ $nombre }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="field-label">{{ __('Precio') }}</span>
                <input type="number" step="0.01" wire:model="precio" value="{{ $precio }}" class="field-input mt-1.5" placeholder="0.00">
            </label>
            <label class="block">
                <span class="field-label">{{ __('Vigente desde') }}</span>
                <input type="date" wire:model="vigenteDesde" value="{{ $vigenteDesde }}" class="field-input mt-1.5">
            </label>
        </div>
        <div class="mt-3">
            <x-submit-button wire:click="agregarPrecio">{{ __('Agregar precio') }}</x-submit-button>
        </div>
    </section>
</div>
