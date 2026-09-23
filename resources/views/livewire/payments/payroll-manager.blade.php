@php $dinero = fn ($v) => '$'.number_format((float) $v, 2); @endphp

<div class="space-y-4">
    <header class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-ink">{{ __('Nómina') }}</h1>
            <p class="mt-0.5 text-sm text-ink-muted">
                {{ __('Lo que se le paga a la plantilla en el periodo. No calcula impuestos ni timbra: se exporta.') }}
            </p>
        </div>

        <button wire:click="$toggle('creando')"
                class="rounded-lg bg-accent-500 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-accent-600">
            {{ __('Nueva nómina') }}
        </button>
    </header>

    @include('partials.validation-errors')

    @if (session('status'))
        <div class="alert-ok rounded-lg px-3 py-2 text-sm">{{ session('status') }}</div>
    @endif

    @if ($creando)
        <div class="rounded-2xl border border-line bg-panel p-4">
            <p class="text-sm font-medium text-ink">{{ __('Nueva nómina') }}</p>
            <p class="mt-1 text-xs text-ink-muted">
                {{ __('Se propone el sueldo de cada empleado por los días del periodo, más las liquidaciones de viaje que aún no se han pagado en otra nómina.') }}
            </p>

            <div class="mt-3 grid gap-3 sm:grid-cols-4">
                <label class="block">
                    <span class="field-label">{{ __('Desde') }}</span>
                    <input type="date" wire:model="desde" value="{{ $desde }}" class="field-input mt-1.5">
                </label>
                <label class="block">
                    <span class="field-label">{{ __('Hasta') }}</span>
                    <input type="date" wire:model="hasta" value="{{ $hasta }}" class="field-input mt-1.5">
                </label>
                <label class="block">
                    <span class="field-label">{{ __('Periodicidad') }}</span>
                    <select wire:model="periodicidad" class="field-input mt-1.5">
                        <option value="semanal" @selected($periodicidad === 'semanal')>{{ __('Semanal') }}</option>
                        <option value="quincenal" @selected($periodicidad === 'quincenal')>{{ __('Quincenal') }}</option>
                        <option value="mensual" @selected($periodicidad === 'mensual')>{{ __('Mensual') }}</option>
                    </select>
                </label>
                <div class="flex items-end">
                    <x-submit-button wire:click="crear">{{ __('Crear') }}</x-submit-button>
                </div>
            </div>
        </div>
    @endif

    <div class="overflow-x-auto rounded-2xl border border-line bg-panel">
        <table class="w-full min-w-[46rem] text-sm">
            <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                    @foreach ([__('Folio'), __('Periodo'), __('Periodicidad'), __('Estado'), ''] as $th)
                        <th class="px-3 py-2.5 text-left font-semibold">{{ $th }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($nominas as $n)
                    <tr class="transition hover:bg-raised">
                        <td class="px-3 py-2">
                            <button wire:click="ver({{ $n->nomina_id }})"
                                    class="font-medium text-brand hover:underline">{{ $n->numero }}</button>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-ink-muted">
                            {{ \Illuminate\Support\Carbon::parse($n->desde)->format('d/m/Y') }}
                            – {{ \Illuminate\Support\Carbon::parse($n->hasta)->format('d/m/Y') }}
                        </td>
                        <td class="px-3 py-2 text-ink-soft">
                            {{ __(ucfirst($n->periodicidad)) }}
                        </td>
                        <td class="px-3 py-2">
                            <span class="{{ $n->estado === 'pagada' ? 'badge-ok' : 'badge-warn' }} rounded px-2 py-0.5 text-xs">
                                {{ $n->estado === 'pagada' ? __('Pagada') : __('Abierta') }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <button wire:click="exportar({{ $n->nomina_id }})"
                                    class="rounded-lg border border-line px-2.5 py-1 text-xs text-ink-soft transition hover:bg-raised">
                                {{ __('Exportar') }}
                            </button>
                            @if ($n->estado === 'abierta')
                                <button wire:click="pagar({{ $n->nomina_id }})"
                                        wire:confirm="{{ __('¿Marcar esta nómina como pagada? Después ya no se podrá modificar.') }}"
                                        class="rounded-lg border border-line px-2.5 py-1 text-xs text-ink-soft transition hover:bg-raised">
                                    {{ __('Marcar pagada') }}
                                </button>
                                <button wire:click="borrar({{ $n->nomina_id }})"
                                        wire:confirm="{{ __('¿Borrar esta nómina y todos sus renglones?') }}"
                                        class="rounded-lg border border-line px-2.5 py-1 text-xs text-ink-soft transition hover:bg-raised">
                                    {{ __('Borrar') }}
                                </button>
                            @elseif (auth()->user()?->isSuperAdmin())
                                <button wire:click="reabrir({{ $n->nomina_id }})"
                                        class="rounded-lg border border-line px-2.5 py-1 text-xs text-ink-soft transition hover:bg-raised">
                                    {{ __('Reabrir') }}
                                </button>
                            @endif
                        </td>
                    </tr>

                    @if ($abierta === $n->nomina_id)
                        <tr>
                            <td colspan="5" class="bg-raised px-3 py-4">
                                <table class="w-full text-sm">
                                    <thead class="text-xs uppercase tracking-wide text-ink-faint">
                                        <tr>
                                            <th class="py-1.5 pr-3 text-left font-semibold">{{ __('Empleado') }}</th>
                                            <th class="py-1.5 pr-3 text-right font-semibold">{{ __('Percepciones') }}</th>
                                            <th class="py-1.5 pr-3 text-right font-semibold">{{ __('Deducciones') }}</th>
                                            <th class="py-1.5 pr-3 text-right font-semibold">{{ __('Neto') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-line">
                                        @foreach ($detalle as $e)
                                            <tr>
                                                <td class="py-1.5 pr-3">
                                                    <span class="text-ink">{{ $e->nombre }}</span>
                                                    @if ($e->puesto)
                                                        <span class="ml-1 text-xs text-ink-faint">{{ $e->puesto }}</span>
                                                    @endif

                                                    {{-- El desglose va debajo del nombre: es lo que se reclama
                                                         cuando el neto no cuadra, y así se ve sin otro clic. --}}
                                                    <span class="mt-0.5 block text-xs text-ink-faint">
                                                        @foreach ($renglones[$e->empleado_id] ?? [] as $r)
                                                            <span class="mr-3 inline-block whitespace-nowrap">
                                                                {{ $r->concepto }}
                                                                <span class="{{ $r->tipo === 'deduccion' ? 'text-brand' : 'text-ink-soft' }}">
                                                                    {{ $r->tipo === 'deduccion' ? '−' : '+' }}{{ $dinero($r->importe) }}
                                                                </span>
                                                                @if ($n->estado === 'abierta')
                                                                    <button wire:click="quitarRenglon({{ $r->renglon_id }})"
                                                                            title="{{ __('Quitar') }}"
                                                                            class="ml-0.5 px-0.5 hover:text-brand">×</button>
                                                                @endif
                                                            </span>
                                                        @endforeach
                                                    </span>
                                                </td>
                                                <td class="py-1.5 pr-3 text-right tabular-nums text-ink">{{ $dinero($e->percepciones) }}</td>
                                                <td class="py-1.5 pr-3 text-right tabular-nums {{ $e->deducciones > 0 ? 'text-brand' : 'text-ink-faint' }}">{{ $dinero($e->deducciones) }}</td>
                                                <td class="py-1.5 pr-3 text-right font-semibold tabular-nums text-ink">{{ $dinero($e->neto) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot class="border-t border-line">
                                        <tr>
                                            <td class="pt-2 text-xs text-ink-muted">
                                                {{ $totales['empleados'] }} {{ mb_strtolower(__('Empleados')) }}
                                            </td>
                                            <td class="pt-2 text-right tabular-nums text-ink-soft">{{ $dinero($totales['percepciones']) }}</td>
                                            <td class="pt-2 text-right tabular-nums text-ink-soft">{{ $dinero($totales['deducciones']) }}</td>
                                            <td class="pt-2 text-right text-base font-semibold tabular-nums text-ink">{{ $dinero($totales['neto']) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>

                                @if ($n->estado === 'abierta')
                                    <div class="mt-4 grid gap-2 sm:grid-cols-5">
                                        <select wire:model="empleado" class="field-input">
                                            <option value="">{{ __('Empleado') }}</option>
                                            @foreach ($empleados as $id => $nombre)
                                                <option value="{{ $id }}" @selected((string) $id === $empleado)>{{ $nombre }}</option>
                                            @endforeach
                                        </select>
                                        <input type="text" wire:model="concepto" value="{{ $concepto }}"
                                               class="field-input sm:col-span-2" placeholder="{{ __('Concepto') }}">
                                        <select wire:model="tipo" class="field-input">
                                            <option value="percepcion" @selected($tipo === 'percepcion')>{{ __('Percepción') }}</option>
                                            <option value="deduccion" @selected($tipo === 'deduccion')>{{ __('Deducción') }}</option>
                                        </select>
                                        <div class="flex gap-2">
                                            <input type="number" step="0.01" wire:model="importe" value="{{ $importe }}"
                                                   class="field-input" placeholder="0.00">
                                            <button wire:click="agregarRenglon"
                                                    class="shrink-0 rounded-lg border border-line px-3 text-sm text-ink-soft transition hover:bg-panel">
                                                {{ __('Agregar') }}
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="5" class="px-3 py-12 text-center text-ink-faint">{{ __('No hay nóminas.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $nominas->links() }}
</div>
