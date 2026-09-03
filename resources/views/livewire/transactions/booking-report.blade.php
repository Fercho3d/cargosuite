@use('App\Support\PaymentStatus')

@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $utilidad = fn ($fila) => (float) $fila->income - (float) $fila->expense;

    // Totales de la página: son gratis y equivalen al `pageSummary` del original.
    $pagina = [
        'income' => $rows->sum(fn ($f) => (float) $f->income),
        'expense' => $rows->sum(fn ($f) => (float) $f->expense),
    ];
@endphp

<div class="space-y-4">

    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-ink">{{ __('Reporte por booking') }}</h2>
            <p class="text-sm text-ink-muted">{{ __('Ingreso, egreso y utilidad de cada embarque, sin IVA y en pesos.') }}</p>
        </div>
        <span class="text-xs text-ink-faint">
            {{ number_format($rows->total()) }} bookings · consulta en {{ $queryMs }} ms
        </span>
    </header>

    {{-- Filtros --}}
    <div class="card p-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="block">
                <span class="field-label text-xs">{{ __('Booking') }}</span>
                <input type="text" wire:model.live.debounce.400ms="bookingNumber" value="{{ $bookingNumber }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('MEX…') }}">
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Número de transacción') }}</span>
                <input type="text" wire:model.live.debounce.400ms="tranNumber" value="{{ $tranNumber }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('F-1234') }}">
            </label>

            <label class="block">
                <span class="field-label text-xs">
                    Carga del booking <span class="text-ink-faint">{{ __('(dd/mm/aaaa - dd/mm/aaaa)') }}</span>
                </span>
                <input type="text" wire:model.live.debounce.600ms="dates" value="{{ $dates }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="01/01/2025 - 31/12/2025">
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Estado de pago') }}</span>
                <select wire:model.live="paid" class="field-input mt-1 py-1.5 text-sm">
                    @foreach (['' => __('Todas'), '0' => __('Sin pagar'), '2' => __('Parciales'), '1' => __('Pagadas')] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected((string) $valor === $paid)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="mt-3 flex flex-wrap items-end gap-2">
            <button type="button" wire:click="clearFilters" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Limpiar filtros') }}</button>
            <button type="button" wire:click="calculateTotals" wire:loading.attr="disabled" wire:target="calculateTotals"
                    class="btn-ghost !px-3 !py-1.5 text-xs">
                <x-spinner wire:loading wire:target="calculateTotals" class="h-3.5 w-3.5" />
                {{ __('Sumar todo el filtro') }}
            </button>

            <a href="{{ $this->exportUrl() }}"
               title="{{ __('Baja todo el filtro, no solo esta página.') }}"
               class="btn-ghost !px-3 !py-1.5 text-xs">
                <svg class="mr-1 inline h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                     stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>
                </svg>
                {{ __('Exportar a Excel') }}
            </a>

            <label class="ml-auto flex items-center gap-2 text-xs text-ink-muted">
                {{ __('Por página') }}
                <select wire:model.live="perPage" class="field-input !w-auto py-1 text-xs">
                    @foreach ([25, 50, 100, 200] as $n)
                        <option value="{{ $n }}" @selected($n === $perPage)>{{ $n }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        @if ($totals !== null)
            <div class="mt-3 grid gap-2 border-t border-line pt-3 text-sm sm:grid-cols-3">
                @foreach ([
                    [__('Ingreso del filtro'), $totals['income']],
                    [__('Egreso del filtro'), $totals['expense']],
                    [__('Utilidad del filtro'), $totals['income'] - $totals['expense']],
                ] as [$etiqueta, $valor])
                    <div class="flex justify-between gap-2 sm:block">
                        <span class="text-xs uppercase tracking-wide text-ink-faint">{{ $etiqueta }}</span>
                        <span class="font-semibold tabular-nums {{ $valor < 0 ? 'text-brand' : 'text-ink' }} sm:mt-0.5 sm:block">
                            {{ $money($valor) }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Resultados --}}
    <div class="relative rounded-xl border border-line bg-panel">
        <div wire:loading.delay class="absolute inset-0 z-20 rounded-xl bg-panel/75 text-center backdrop-blur-[1px]">
            <span class="mt-14 inline-flex items-center gap-2 rounded-full border border-line bg-panel px-4 py-2 text-sm text-ink-muted shadow-lg">
                <x-spinner class="h-4 w-4 text-brand" />
                {{ __('Actualizando…') }}
            </span>
        </div>

        {{-- Tarjetas en móvil --}}
        <ul class="divide-y divide-line md:hidden">
            @forelse ($rows as $fila)
                <li class="space-y-2 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('transactions.booking', $fila->booking_id) }}" wire:navigate
                           class="min-w-0 truncate font-semibold text-brand hover:underline">
                            {{ trim((string) $fila->booking_number) ?: '—' }}
                        </a>
                        <span class="shrink-0 font-semibold tabular-nums {{ $utilidad($fila) < 0 ? 'text-brand' : 'text-ink' }}">
                            {{ $money($utilidad($fila)) }}
                        </span>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-faint">{{ __('Ingreso') }}</dt>
                            <dd class="tabular-nums text-ink-soft">{{ $money($fila->income) }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-faint">{{ __('Egreso') }}</dt>
                            <dd class="tabular-nums text-ink-soft">{{ $money($fila->expense) }}</dd>
                        </div>
                    </dl>
                </li>
            @empty
                <li class="px-4 py-12 text-center text-sm text-ink-faint">{{ __('No hay bookings con estos filtros.') }}</li>
            @endforelse
        </ul>

        {{-- Tabla desde md --}}
        <div class="hidden overflow-x-auto md:block">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line bg-panel text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Booking') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Transacción') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Estado') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Alta del booking') }}</th>
                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('Ingreso') }}</th>
                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('Egreso') }}</th>
                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('Utilidad') }}</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    @forelse ($rows as $fila)
                        @php $estado = PaymentStatus::for($fila); @endphp
                        <tr class="transition hover:bg-raised">
                            <td class="whitespace-nowrap px-3 py-2">
                                <a href="{{ route('transactions.booking', $fila->booking_id) }}" wire:navigate
                                   class="text-brand hover:underline">{{ trim((string) $fila->booking_number) ?: '—' }}</a>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2">
                                @if ($fila->tran_number)
                                    <a href="{{ route('transactions.show', $fila->transc_id) }}" wire:navigate
                                       title="{{ __('Al agrupar por booking, el número es el de una de las transacciones del grupo.') }}"
                                       class="text-brand hover:underline">{{ $fila->tran_number }}</a>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-2"><span class="{{ $estado->classes() }}">{{ $estado->label() }}</span></td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">
                                {{ $fila->booking_created_at ? \Illuminate\Support\Carbon::parse($fila->booking_created_at)->format('d/m/Y') : '—' }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-soft">{{ $money($fila->income) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-soft">{{ $money($fila->expense) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums {{ $utilidad($fila) < 0 ? 'text-brand' : 'text-ink' }}">
                                {{ $money($utilidad($fila)) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-12 text-center text-ink-faint">{{ __('No hay bookings con estos filtros.') }}</td>
                        </tr>
                    @endforelse
                </tbody>

                @if ($rows->isNotEmpty())
                    <tfoot class="border-t border-line bg-panel text-sm font-semibold">
                        <tr>
                            <td colspan="4" class="px-3 py-2.5 text-ink-muted">{{ __('Total de esta página') }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-ink">{{ $money($pagina['income']) }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-ink">{{ $money($pagina['expense']) }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums {{ $pagina['income'] - $pagina['expense'] < 0 ? 'text-brand' : 'text-ink' }}">
                                {{ $money($pagina['income'] - $pagina['expense']) }}
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- Totales de la página, en móvil --}}
    <div class="card space-y-1.5 p-4 text-sm md:hidden">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-faint">{{ __('Total de esta página') }}</p>
        <div class="flex justify-between gap-2">
            <span class="text-ink-muted">{{ __('Ingreso') }}</span>
            <span class="tabular-nums text-ink-soft">{{ $money($pagina['income']) }}</span>
        </div>
        <div class="flex justify-between gap-2">
            <span class="text-ink-muted">{{ __('Egreso') }}</span>
            <span class="tabular-nums text-ink-soft">{{ $money($pagina['expense']) }}</span>
        </div>
        <div class="flex justify-between gap-2 border-t border-line pt-1.5">
            <span class="text-ink-muted">{{ __('Utilidad') }}</span>
            <span class="font-semibold tabular-nums text-ink">{{ $money($pagina['income'] - $pagina['expense']) }}</span>
        </div>
    </div>

    <div>{{ $rows->links() }}</div>
</div>
