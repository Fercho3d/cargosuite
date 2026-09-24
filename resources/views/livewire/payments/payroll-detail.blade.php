@php
    $dinero = fn ($v) => '$'.number_format((float) $v, 2);
    $fecha = fn ($v) => \Illuminate\Support\Carbon::parse($v)->format('d/m/Y');
    $pagada = $n->estado === 'pagada';
    $timbrado = config('timbrado.habilitado');
@endphp

<div class="space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('payments.payroll') }}" wire:navigate class="text-xs text-brand hover:underline">← {{ __('Nómina') }}</a>
            <h1 class="mt-1 text-lg font-semibold text-ink">
                {{ $n->numero }}
                <span class="{{ $pagada ? 'badge-ok' : 'badge-warn' }} ml-2 rounded px-2 py-0.5 align-middle text-xs font-normal">
                    {{ $pagada ? __('Pagada') : __('Abierta') }}
                </span>
            </h1>
            <p class="mt-0.5 text-sm text-ink-muted">
                {{ $fecha($n->desde) }} – {{ $fecha($n->hasta) }} · {{ __(ucfirst($n->periodicidad)) }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <button wire:click="exportar"
                    class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-soft transition hover:bg-raised">
                {{ __('Exportar') }}
            </button>
            @if (! $pagada)
                <button wire:click="pagar"
                        wire:confirm="{{ __('¿Marcar esta nómina como pagada? Después ya no se podrá modificar.') }}"
                        class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-soft transition hover:bg-raised">
                    {{ __('Marcar toda pagada') }}
                </button>
                <button wire:click="borrar"
                        wire:confirm="{{ __('¿Borrar esta nómina y todos sus renglones?') }}"
                        class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-soft transition hover:bg-raised">
                    {{ __('Borrar') }}
                </button>
            @elseif (auth()->user()?->isSuperAdmin())
                <button wire:click="reabrir"
                        class="rounded-lg border border-line px-3 py-1.5 text-sm text-ink-soft transition hover:bg-raised">
                    {{ __('Reabrir') }}
                </button>
            @endif
        </div>
    </header>

    @include('partials.validation-errors')

    @if (session('status'))
        <div class="alert-ok rounded-lg px-3 py-2 text-sm">{{ session('status') }}</div>
    @endif

    @if ($timbrado && ($pagada || $recibos->whereNotNull('pagado_en')->isNotEmpty()))
        <div class="flex flex-wrap items-end gap-2 rounded-2xl border border-line bg-panel p-4">
            @if ($n->company_id === null)
                <label class="block">
                    <span class="field-label">{{ __('Compañía que timbra') }}</span>
                    <select wire:model="emisor" class="field-input mt-1.5">
                        <option value="">{{ __('Elige…') }}</option>
                        @foreach ($companias as $id => $nombre)
                            <option value="{{ $id }}" @selected((string) $id === $emisor)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <x-submit-button wire:click="timbrar">{{ __('Timbrar todo lo pagado') }}</x-submit-button>
        </div>
    @endif

    <div class="overflow-x-auto rounded-2xl border border-line bg-panel">
        <table class="w-full min-w-[52rem] text-sm">
            <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                    <th class="px-3 py-2.5 text-left font-semibold">{{ __('Empleado') }}</th>
                    <th class="px-3 py-2.5 text-right font-semibold">{{ __('Percepciones') }}</th>
                    <th class="px-3 py-2.5 text-right font-semibold">{{ __('Deducciones') }}</th>
                    <th class="px-3 py-2.5 text-right font-semibold">{{ __('Neto') }}</th>
                    <th class="px-3 py-2.5 text-right font-semibold" title="{{ __('IMSS, retiro, cesantía e INFONAVIT que paga la empresa. No baja el neto.') }}">{{ __('Costo patronal') }}</th>
                    <th class="px-3 py-2.5 text-right font-semibold">{{ __('Pago') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($detalle as $e)
                    @php
                        $recibo = $recibos[$e->empleado_id] ?? null;
                        $pagadoEn = $recibo?->pagado_en ?? ($pagada ? $n->pagada_en : null);
                        $editable = ! $pagada && $pagadoEn === null;
                    @endphp
                    <tr class="align-top">
                        <td class="px-3 py-2">
                            <span class="text-ink">{{ $e->nombre }}</span>
                            @if ($e->puesto)
                                <span class="ml-1 text-xs text-ink-faint">{{ $e->puesto }}</span>
                            @endif

                            {{-- El desglose va debajo del nombre: es lo que se reclama
                                 cuando el neto no cuadra, y así se ve sin otro clic. --}}
                            <span class="mt-0.5 block text-xs text-ink-faint">
                                @foreach ($renglones[$e->empleado_id] ?? [] as $r)
                                    <span class="mr-3 inline-block whitespace-nowrap {{ $r->tipo === 'patronal' ? 'italic' : '' }}">
                                        {{ $r->concepto }}
                                        <span class="{{ $r->tipo === 'deduccion' ? 'text-(--danger-ink)' : 'text-ink-soft' }}">
                                            {{ ['deduccion' => '−', 'percepcion' => '+'][$r->tipo] ?? '' }}{{ $dinero($r->importe) }}
                                        </span>
                                        {{-- Lo automático se rehace solo: quitarlo no serviría. --}}
                                        @if ($editable && ! $r->automatico)
                                            <button wire:click="quitarRenglon({{ $r->renglon_id }})"
                                                    title="{{ __('Quitar') }}"
                                                    class="ml-0.5 px-0.5 hover:text-brand">×</button>
                                        @endif
                                    </span>
                                @endforeach
                            </span>

                            @if ($recibo?->estado === 'timbrado')
                                <span class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                    <span class="badge-ok rounded px-2 py-0.5">{{ __('Timbrado') }}</span>
                                    <span class="font-mono text-ink-faint">{{ $recibo->uuid }}</span>
                                    <button wire:click="descargarRecibo({{ $recibo->recibo_id }}, 'xml')" class="text-brand hover:underline">XML</button>
                                    <button wire:click="descargarRecibo({{ $recibo->recibo_id }}, 'pdf')" class="text-brand hover:underline">PDF</button>
                                    <button wire:click="cancelarRecibo({{ $recibo->recibo_id }})"
                                            wire:confirm="{{ __('¿Cancelar este recibo ante el SAT?') }}"
                                            class="text-ink-faint hover:text-brand">{{ __('Cancelar') }}</button>
                                </span>
                            @elseif ($recibo?->estado === 'error')
                                <span class="mt-1 block text-xs text-(--danger-ink)">{{ __('No se timbró') }}: {{ $recibo->mensaje }}</span>
                            @elseif ($recibo?->estado === 'cancelado')
                                <span class="mt-1 block text-xs text-ink-faint">{{ __('Recibo cancelado') }} · <span class="font-mono">{{ $recibo->uuid }}</span></span>
                            @elseif ($pagadoEn !== null && $timbrado && ! $timbrable->has($e->empleado_id))
                                <span class="mt-1 block text-xs text-ink-faint">{{ __('Sin régimen de nómina: no se timbra.') }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-ink">{{ $dinero($e->percepciones) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums {{ $e->deducciones > 0 ? 'text-(--danger-ink)' : 'text-ink-faint' }}">{{ $dinero($e->deducciones) }}</td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums text-ink">{{ $dinero($e->neto) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-ink-faint">{{ $dinero($e->patronal) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            @if ($pagadoEn === null)
                                <button wire:click="pagarEmpleado({{ $e->empleado_id }})"
                                        wire:confirm="{{ __('¿Registrar el pago de :nombre? Después su recibo ya no se podrá modificar.', ['nombre' => $e->nombre]) }}"
                                        class="rounded-lg border border-line px-2.5 py-1 text-xs text-ink-soft transition hover:bg-raised">
                                    {{ __('Pagar') }}
                                </button>
                            @else
                                <span class="badge-ok rounded px-2 py-0.5 text-xs">{{ __('Pagado') }} {{ $fecha($pagadoEn) }}</span>
                                @if ($timbrado && $timbrable->has($e->empleado_id) && $recibo?->estado !== 'timbrado')
                                    <button wire:click="timbrar({{ $e->empleado_id }})"
                                            class="mt-1 block w-full rounded-lg border border-line px-2.5 py-1 text-xs text-ink-soft transition hover:bg-raised">
                                        {{ __('Timbrar') }}
                                    </button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t border-line">
                <tr>
                    <td class="px-3 py-2 text-xs text-ink-muted">
                        {{ $totales['empleados'] }} {{ mb_strtolower(__('Empleados')) }}
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums text-ink-soft">{{ $dinero($totales['percepciones']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-ink-soft">{{ $dinero($totales['deducciones']) }}</td>
                    <td class="px-3 py-2 text-right text-base font-semibold tabular-nums text-ink">{{ $dinero($totales['neto']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-ink-soft">{{ $dinero($totales['patronal']) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if (! $pagada)
        <div class="rounded-2xl border border-line bg-panel p-4">
            <p class="text-sm font-medium text-ink">{{ __('Agregar percepción o deducción') }}</p>
            <div class="mt-3 grid gap-2 sm:grid-cols-5">
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
                            class="shrink-0 rounded-lg border border-line px-3 text-sm text-ink-soft transition hover:bg-raised">
                        {{ __('Agregar') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
