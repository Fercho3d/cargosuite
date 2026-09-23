@php
    $dinero = fn ($v) => '$'.number_format((float) $v, 2);
    // `$num` y no `$cantidad`: el componente ya publica una propiedad con ese
    // nombre y el ayudante la pisaría, dejando un Closure donde va el valor.
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
@endphp

<div class="space-y-4">
    <header class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-ink">{{ __('Almacén') }}</h1>
            <p class="mt-0.5 text-sm text-ink-muted">
                {{ __('Qué refacciones hay, cuáles se están acabando y a dónde se fue cada una.') }}
            </p>
        </div>

        <div class="flex gap-2">
            <a href="{{ route('catalogs.show', 'refacciones') }}" wire:navigate
               class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-soft transition hover:bg-raised">
                {{ __('Dar de alta refacciones') }}
            </a>
            <button wire:click="exportar"
                    class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-soft transition hover:bg-raised">
                {{ __('Exportar') }}
            </button>
        </div>
    </header>

    @include('partials.validation-errors')

    @if (session('status'))
        <div class="alert-ok rounded-lg px-3 py-2 text-sm">{{ session('status') }}</div>
    @endif

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-2xl border border-line bg-panel p-4">
            <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Valor del almacén') }}</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-ink">{{ $dinero($valor) }}</p>
        </div>
        <div class="rounded-2xl border border-line bg-panel p-4">
            <p class="text-xs uppercase tracking-wide text-ink-muted">{{ __('Por reponer') }}</p>
            <p class="mt-1 text-xl font-semibold tabular-nums {{ $faltantes > 0 ? 'text-brand' : 'text-ink' }}">{{ $faltantes }}</p>
        </div>
        <label class="flex items-end">
            <span class="flex w-full cursor-pointer items-center gap-2 rounded-2xl border border-line bg-panel p-4 text-sm text-ink-soft">
                <input type="checkbox" wire:model.live="soloFaltantes"
                       class="h-4 w-4 rounded border-line bg-panel text-accent-500 focus:ring-accent-500">
                {{ __('Ver solo lo que falta') }}
            </span>
        </label>
    </div>

    <input type="search" wire:model.live.debounce.300ms="buscar" value="{{ $buscar }}"
           class="field-input" placeholder="{{ __('Buscar por nombre, código o categoría…') }}">

    <div class="overflow-x-auto rounded-2xl border border-line bg-panel">
        <table class="w-full min-w-[52rem] text-sm">
            <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                    @foreach ([__('Código'), __('Refacción'), __('Ubicación'), __('Existencia'), __('Mínimo'), __('Costo'), ''] as $th)
                        <th class="px-3 py-2.5 text-left font-semibold">{{ $th }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($refacciones as $r)
                    @php $falta = $r->minimo > 0 && $r->existencia <= $r->minimo; @endphp
                    <tr class="transition hover:bg-raised">
                        <td class="px-3 py-2 text-ink-faint">{{ $r->codigo }}</td>
                        <td class="px-3 py-2">
                            <button wire:click="kardex({{ $r->refaccion_id }})" class="text-left text-ink hover:text-brand">
                                {{ $r->nombre }}
                            </button>
                            @if ($r->categoria)
                                <span class="ml-1 text-xs text-ink-faint">{{ $r->categoria }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs text-ink-muted">{{ $r->ubicacion ?: '—' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums {{ $falta ? 'font-semibold text-brand' : 'text-ink' }}">
                            {{ $num($r->existencia) }}
                            <span class="text-xs text-ink-faint">{{ $r->medida ?: __('pza') }}</span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-ink-faint">{{ $num($r->minimo) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-ink-soft">{{ $dinero($r->costo) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <button wire:click="mover({{ $r->refaccion_id }}, 'entrada')"
                                    class="rounded-lg border border-line px-2 py-1 text-xs text-ink-soft transition hover:bg-raised">{{ __('Entrada') }}</button>
                            <button wire:click="mover({{ $r->refaccion_id }}, 'salida')"
                                    class="rounded-lg border border-line px-2 py-1 text-xs text-ink-soft transition hover:bg-raised">{{ __('Salida') }}</button>
                            <button wire:click="mover({{ $r->refaccion_id }}, 'ajuste')"
                                    class="rounded-lg border border-line px-2 py-1 text-xs text-ink-soft transition hover:bg-raised">{{ __('Ajuste') }}</button>
                        </td>
                    </tr>

                    @if ($moviendo === $r->refaccion_id)
                        <tr>
                            <td colspan="7" class="bg-raised px-3 py-3">
                                <div class="grid items-end gap-2 sm:grid-cols-5">
                                    <label class="block">
                                        <span class="field-label text-xs">
                                            {{ $tipo === 'ajuste' ? __('Existencia contada') : __('Cantidad') }}
                                        </span>
                                        <input type="number" step="0.01" wire:model="cantidad" value="{{ $cantidad }}" class="field-input mt-1 py-1.5 text-sm">
                                    </label>
                                    @if ($tipo === 'entrada')
                                        <label class="block">
                                            <span class="field-label text-xs">{{ __('Costo') }}</span>
                                            <input type="number" step="0.01" wire:model="costo" value="{{ $costo }}" class="field-input mt-1 py-1.5 text-sm">
                                        </label>
                                        <label class="block">
                                            <span class="field-label text-xs">{{ __('Proveedor') }}</span>
                                            <select wire:model="proveedor" class="field-input mt-1 py-1.5 text-sm">
                                                <option value="">{{ __('Selecciona') }}</option>
                                                @foreach ($proveedores as $id => $nombre)
                                                    <option value="{{ $id }}" @selected((string) $id === $proveedor)>{{ $nombre }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="field-label text-xs">{{ __('Folio') }}</span>
                                            <input type="text" wire:model="folio" value="{{ $folio }}" class="field-input mt-1 py-1.5 text-sm">
                                        </label>
                                    @else
                                        <label class="block">
                                            <span class="field-label text-xs">{{ __('Fecha') }}</span>
                                            <input type="date" wire:model="fecha" value="{{ $fecha }}" class="field-input mt-1 py-1.5 text-sm">
                                        </label>
                                    @endif
                                    <div class="flex gap-2">
                                        <x-submit-button wire:click="guardarMovimiento">{{ __('Guardar') }}</x-submit-button>
                                        <button wire:click="cancelar" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Cancelar') }}</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif

                    @if ($verKardex === $r->refaccion_id)
                        <tr>
                            <td colspan="7" class="bg-raised px-3 py-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ __('Movimientos') }}</p>
                                <table class="mt-2 w-full text-sm">
                                    <tbody class="divide-y divide-line">
                                        @forelse ($movimientos as $m)
                                            <tr>
                                                <td class="w-28 py-1 pr-3 text-xs text-ink-muted">
                                                    {{ \Illuminate\Support\Carbon::parse($m->fecha)->format('d/m/Y') }}
                                                </td>
                                                <td class="w-24 py-1 pr-3 text-xs text-ink-faint">
                                                    {{ ['entrada' => __('Entrada'), 'salida' => __('Salida'), 'ajuste' => __('Ajuste')][$m->tipo] ?? $m->tipo }}
                                                </td>
                                                <td class="w-24 py-1 pr-3 text-right tabular-nums {{ $m->cantidad < 0 ? 'text-brand' : 'text-ink' }}">
                                                    {{ $m->cantidad > 0 ? '+' : '' }}{{ $num($m->cantidad) }}
                                                </td>
                                                <td class="py-1 text-xs text-ink-muted">
                                                    {{ $m->orden ? __('Orden :folio', ['folio' => $m->orden]).($m->unidad ? ' · '.$m->unidad : '') : ($m->proveedor ?: $m->notas) }}
                                                    @if ($m->folio) <span class="text-ink-faint">{{ $m->folio }}</span> @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td class="py-2 text-xs text-ink-faint">{{ __('Sin movimientos.') }}</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-12 text-center text-ink-faint">{{ __('No hay refacciones.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $refacciones->links() }}
</div>
