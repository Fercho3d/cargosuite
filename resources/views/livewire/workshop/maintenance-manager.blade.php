@php $dinero = fn ($v) => '$'.number_format((float) $v, 2); @endphp

<div class="space-y-4">
    <header class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-ink">{{ __('Mantenimiento') }}</h1>
            <p class="mt-0.5 text-sm text-ink-muted">
                {{ __('Qué se le hizo a cada unidad, con qué refacciones y qué costó.') }}
            </p>
        </div>

        <button wire:click="$toggle('creando')"
                class="rounded-lg bg-accent-500 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-accent-600">
            {{ __('Nueva orden') }}
        </button>
    </header>

    @include('partials.validation-errors')

    @if (session('status'))
        <div class="alert-ok rounded-lg px-3 py-2 text-sm">{{ session('status') }}</div>
    @endif

    {{-- Lo que de verdad se usa a diario: qué unidades ya deben servicio. --}}
    @if ($porServicio->isNotEmpty())
        <div class="rounded-2xl border border-line bg-panel p-4">
            <p class="text-sm font-medium text-ink">{{ __('Unidades por servicio') }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($porServicio as $u)
                    <span class="{{ $u->vencido ? 'badge-warn' : '' }} rounded-lg border border-line px-2.5 py-1 text-xs">
                        <span class="font-semibold text-ink">{{ $u->numero }}</span>
                        <span class="text-ink-muted">
                            @if ($u->vencido)
                                {{ __('vencido por :km km', ['km' => number_format(abs($u->faltan))]) }}
                            @else
                                {{ __('faltan :km km', ['km' => number_format($u->faltan)]) }}
                            @endif
                        </span>
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    @if ($creando)
        <div class="rounded-2xl border border-line bg-panel p-4">
            <p class="text-sm font-medium text-ink">{{ __('Nueva orden') }}</p>

            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <label class="block">
                    <span class="field-label">{{ __('Unidad') }}</span>
                    <select wire:model="unidad" class="field-input mt-1.5">
                        <option value="">{{ __('Selecciona') }}</option>
                        @foreach ($unidades as $id => $numero)
                            <option value="{{ $id }}" @selected((string) $id === $unidad)>{{ $numero }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="field-label">{{ __('Tipo') }}</span>
                    <select wire:model="tipo" class="field-input mt-1.5">
                        <option value="preventivo" @selected($tipo === 'preventivo')>{{ __('Preventivo') }}</option>
                        <option value="correctivo" @selected($tipo === 'correctivo')>{{ __('Correctivo') }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="field-label">{{ __('Entrada') }}</span>
                    <input type="date" wire:model="entrada" value="{{ $entrada }}" class="field-input mt-1.5">
                </label>
                <label class="block">
                    <span class="field-label">{{ __('Odómetro') }}</span>
                    <input type="number" wire:model="odometro" value="{{ $odometro }}" class="field-input mt-1.5" placeholder="km">
                </label>
                <label class="block">
                    <span class="field-label">{{ __('Taller') }}</span>
                    <select wire:model.live="taller" class="field-input mt-1.5">
                        <option value="interno" @selected($taller === 'interno')>{{ __('Taller propio') }}</option>
                        <option value="externo" @selected($taller === 'externo')>{{ __('Taller externo') }}</option>
                    </select>
                </label>
                @if ($taller === 'externo')
                    <label class="block">
                        <span class="field-label">{{ __('Proveedor') }}</span>
                        <select wire:model="proveedor" class="field-input mt-1.5">
                            <option value="">{{ __('Selecciona') }}</option>
                            @foreach ($proveedores as $id => $nombre)
                                <option value="{{ $id }}" @selected((string) $id === $proveedor)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <label class="block sm:col-span-2">
                    <span class="field-label">{{ __('Descripción') }}</span>
                    <input type="text" wire:model="descripcion" value="{{ $descripcion }}" class="field-input mt-1.5"
                           placeholder="{{ __('Servicio de 20 000 km, cambio de balatas…') }}">
                </label>
                <label class="block">
                    <span class="field-label">{{ __('Mano de obra') }}</span>
                    <input type="number" step="0.01" wire:model="manoObra" value="{{ $manoObra }}" class="field-input mt-1.5" placeholder="0.00">
                </label>
            </div>

            <div class="mt-3 flex justify-end">
                <x-submit-button wire:click="crear">{{ __('Abrir orden') }}</x-submit-button>
            </div>
        </div>
    @endif

    <div class="flex flex-wrap gap-3">
        <label class="block">
            <span class="field-label text-xs">{{ __('Unidad') }}</span>
            <select wire:model.live="unidadFiltro" class="field-input mt-1 py-1.5 text-sm">
                <option value="">{{ __('Todas') }}</option>
                @foreach ($unidades as $id => $numero)
                    <option value="{{ $id }}" @selected((string) $id === $unidadFiltro)>{{ $numero }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">
            <span class="field-label text-xs">{{ __('Estado') }}</span>
            <select wire:model.live="estadoFiltro" class="field-input mt-1 py-1.5 text-sm">
                <option value="">{{ __('Todos') }}</option>
                <option value="abierto" @selected($estadoFiltro === 'abierto')>{{ __('Abiertas') }}</option>
                <option value="cerrado" @selected($estadoFiltro === 'cerrado')>{{ __('Cerradas') }}</option>
            </select>
        </label>
    </div>

    <div class="overflow-x-auto rounded-2xl border border-line bg-panel">
        <table class="w-full min-w-[52rem] text-sm">
            <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                    @foreach ([__('Folio'), __('Unidad'), __('Tipo'), __('Descripción'), __('Entrada'), __('Total'), ''] as $th)
                        <th class="px-3 py-2.5 text-left font-semibold">{{ $th }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($ordenes as $o)
                    @php $t = \App\Support\Workshop\Maintenance::totales($o->mantenimiento_id); @endphp
                    <tr class="transition hover:bg-raised">
                        <td class="px-3 py-2">
                            <button wire:click="ver({{ $o->mantenimiento_id }})"
                                    class="font-medium text-brand hover:underline">{{ $o->folio }}</button>
                        </td>
                        <td class="px-3 py-2 text-ink">{{ $o->unidad ?: '—' }}</td>
                        <td class="px-3 py-2">
                            <span class="rounded px-2 py-0.5 text-xs {{ $o->tipo === 'correctivo' ? 'badge-warn' : 'badge-ok' }}">
                                {{ $o->tipo === 'correctivo' ? __('Correctivo') : __('Preventivo') }}
                            </span>
                        </td>
                        <td class="max-w-xs truncate px-3 py-2 text-ink-soft">
                            {{ $o->descripcion }}
                            @if ($o->taller === 'externo')
                                <span class="text-xs text-ink-faint">· {{ $o->proveedor ?: __('Taller externo') }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-ink-muted">
                            {{ \Illuminate\Support\Carbon::parse($o->entrada)->format('d/m/Y') }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink">{{ $dinero($t['total']) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            @if ($o->estado === 'abierto')
                                <button wire:click="cerrar({{ $o->mantenimiento_id }})"
                                        wire:confirm="{{ __('¿Cerrar la orden? La unidad quedará con su servicio al día y ya no se le podrán poner refacciones.') }}"
                                        class="rounded-lg border border-line px-2.5 py-1 text-xs text-ink-soft transition hover:bg-raised">
                                    {{ __('Cerrar') }}
                                </button>
                            @else
                                <span class="text-xs text-ink-faint">
                                    {{ $o->salida ? \Illuminate\Support\Carbon::parse($o->salida)->format('d/m/Y') : '' }}
                                </span>
                            @endif
                        </td>
                    </tr>

                    @if ($abierta === $o->mantenimiento_id)
                        <tr>
                            <td colspan="7" class="bg-raised px-3 py-4">
                                <table class="w-full text-sm">
                                    <tbody class="divide-y divide-line">
                                        @forelse ($renglones as $r)
                                            <tr>
                                                <td class="py-1.5 pr-3 text-ink">
                                                    {{ $r->nombre }}
                                                    <span class="text-xs text-ink-faint">{{ $r->codigo }}</span>
                                                </td>
                                                <td class="w-28 py-1.5 pr-3 text-right text-xs text-ink-muted">
                                                    {{ rtrim(rtrim(number_format((float) $r->cantidad, 2), '0'), '.') }}
                                                    {{ $r->medida ?: __('pza') }}
                                                </td>
                                                <td class="w-32 py-1.5 pr-3 text-right tabular-nums text-ink-soft">{{ $dinero($r->costo) }}</td>
                                                <td class="w-32 py-1.5 pr-3 text-right tabular-nums text-ink">{{ $dinero($r->cantidad * $r->costo) }}</td>
                                                <td class="w-10 py-1.5 text-right">
                                                    @if ($o->estado === 'abierto')
                                                        <button wire:click="quitarRefaccion({{ $r->renglon_id }})"
                                                                title="{{ __('Quitar') }}"
                                                                class="text-xs text-ink-faint hover:text-brand">×</button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td class="py-2 text-xs text-ink-faint">{{ __('Sin refacciones.') }}</td></tr>
                                        @endforelse
                                    </tbody>
                                    <tfoot class="border-t border-line">
                                        <tr>
                                            <td colspan="3" class="pt-2 text-xs text-ink-muted">
                                                {{ __('Mano de obra') }} {{ $dinero($totales['mano_obra']) }} ·
                                                {{ __('Refacciones') }} {{ $dinero($totales['refacciones']) }}
                                            </td>
                                            <td class="pt-2 text-right text-base font-semibold tabular-nums text-ink">{{ $dinero($totales['total']) }}</td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>

                                @if ($o->estado === 'abierto')
                                    <div class="mt-4 grid gap-2 sm:grid-cols-4">
                                        <select wire:model="refaccion" class="field-input sm:col-span-2">
                                            <option value="">{{ __('Refacción') }}</option>
                                            @foreach ($refacciones as $r)
                                                <option value="{{ $r->refaccion_id }}" @selected((string) $r->refaccion_id === $refaccion)>
                                                    {{ $r->nombre }} ({{ rtrim(rtrim(number_format((float) $r->existencia, 2), '0'), '.') }})
                                                </option>
                                            @endforeach
                                        </select>
                                        <input type="number" step="0.01" wire:model="cantidad" value="{{ $cantidad }}"
                                               class="field-input" placeholder="{{ __('Cantidad') }}">
                                        <button wire:click="agregarRefaccion"
                                                class="rounded-lg border border-line px-3 text-sm text-ink-soft transition hover:bg-panel">
                                            {{ __('Poner en la unidad') }}
                                        </button>
                                    </div>
                                    <p class="mt-1.5 text-xs text-ink-faint">{{ __('Al ponerla, se descuenta del almacén.') }}</p>
                                @endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-12 text-center text-ink-faint">{{ __('No hay órdenes de mantenimiento.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $ordenes->links() }}
</div>
