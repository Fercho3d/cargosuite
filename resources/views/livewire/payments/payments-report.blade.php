@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $llave = $this->keyColumn();

    $nombre = fn ($fila) => match ($mode) {
        'vendor' => $fila->providerName ?: __('Sin proveedor'),
        'general' => ((int) $fila->type === 1 ? __('Cobros a clientes') : __('Pagos a proveedores')),
        default => $fila->clientName ?: __('Sin cliente'),
    };

    // Columnas de dinero, iguales en las tres pantallas.
    $columnas = [
        ['Sub 0 %', 'sub_0_paid'],
        ['Sub 16 %', 'sub_16_paid'],
        ['IVA 16 %', 'tax_16_paid'],
        ['Ret. IVA', 'tax_ret_paid'],
    ];

    $totales = collect($columnas)->mapWithKeys(
        fn ($c) => [$c[1] => $filas->sum(fn ($f) => (float) $f->{$c[1]})]
    )->all();
    $totalGeneral = $filas->sum(fn ($f) => (float) $f->total_paid);
@endphp

<div class="space-y-4">

    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-ink">{{ $this->title() }}</h2>
            <p class="text-sm text-ink-muted">
                {{ __('Lo efectivamente cobrado o pagado, repartido entre los conceptos de cada documento.') }}
            </p>
        </div>
        <span class="text-xs text-ink-faint">
            {{ number_format($filas->count()) }} renglones · consulta en {{ $queryMs }} ms
        </span>
    </header>

    {{-- Filtros --}}
    <div class="card p-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <label class="block">
                <span class="field-label text-xs">{{ __('Fecha de la solicitud') }} <span class="text-ink-faint">{{ __('(rango)') }}</span></span>
                <input type="text" wire:model.live.debounce.600ms="dates" value="{{ $dates }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="01/01/2025 - 31/12/2025">
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Revaluar al tipo de cambio del') }} <span class="text-ink-faint">{{ __('(dd/mm/aaaa)') }}</span></span>
                <input type="text" wire:model.live.debounce.600ms="datePay" value="{{ $datePay }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="31/12/2025">
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Banco') }}</span>
                <select wire:model.live="bankId" class="field-input mt-1 py-1.5 text-sm">
                    <option value="">{{ __('Todos') }}</option>
                    @foreach ($banks as $id => $etiqueta)
                        <option value="{{ $id }}" @selected((string) $id === $bankId)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="mt-3">
            <button type="button" wire:click="clearFilters" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Limpiar filtros') }}</button>
        </div>
    </div>

    {{-- Resultados --}}
    <div class="relative rounded-xl border border-line bg-panel">
        <div wire:loading.delay class="absolute inset-0 z-20 rounded-xl bg-panel/75 text-center backdrop-blur-[1px]">
            <span class="mt-14 inline-flex items-center gap-2 rounded-full border border-line bg-panel px-4 py-2 text-sm text-ink-muted shadow-lg">
                <x-spinner class="h-4 w-4 text-brand" />
                {{ __('Actualizando…') }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2.5 text-left font-semibold">
                            {{ $mode === 'vendor' ? __('Proveedor') : ($mode === 'general' ? __('Tipo') : __('Cliente')) }}
                        </th>
                        @foreach ($columnas as [$etiqueta, $columna])
                            <th class="px-4 py-2.5 text-right font-semibold">{{ $etiqueta }}</th>
                        @endforeach
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Total') }}</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    @forelse ($filas as $fila)
                        @php $clave = (string) ($fila->{$llave} ?? ''); @endphp
                        <tr class="cursor-pointer transition hover:bg-raised" wire:click="toggle('{{ $clave }}')">
                            <td class="px-4 py-2">
                                <span class="inline-flex items-center gap-2">
                                    <svg class="h-3.5 w-3.5 shrink-0 text-ink-faint transition {{ $expanded === $clave ? 'rotate-90' : '' }}"
                                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                    </svg>
                                    <span class="text-ink">{{ $nombre($fila) }}</span>
                                </span>
                            </td>
                            @foreach ($columnas as [$etiqueta, $columna])
                                <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ $money($fila->{$columna}) }}</td>
                            @endforeach
                            <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums {{ (float) $fila->total_paid < 0 ? 'text-brand' : 'text-ink' }}">
                                {{ $money($fila->total_paid) }}
                            </td>
                        </tr>

                        {{-- Desglose: las solicitudes de pago que forman el renglón --}}
                        @if ($expanded === $clave)
                            <tr>
                                <td colspan="{{ count($columnas) + 2 }}" class="bg-raised/50 p-0">
                                    <div class="overflow-x-auto p-4">
                                        <table class="min-w-full text-xs">
                                            <thead class="text-[11px] uppercase tracking-wide text-ink-faint">
                                                <tr>
                                                    <th class="px-3 py-1.5 text-left font-semibold">{{ __('Solicitud') }}</th>
                                                    <th class="px-3 py-1.5 text-left font-semibold">{{ __('Fecha') }}</th>
                                                    <th class="px-3 py-1.5 text-left font-semibold">{{ __('Banco') }}</th>
                                                    <th class="px-3 py-1.5 text-left font-semibold">{{ __('Divisa') }}</th>
                                                    <th class="px-3 py-1.5 text-right font-semibold">{{ __('TC') }}</th>
                                                    <th class="px-3 py-1.5 text-right font-semibold">{{ __('Importe') }}</th>
                                                    <th class="px-3 py-1.5 text-right font-semibold">{{ __('Total') }}</th>
                                                    <th class="px-3 py-1.5 text-right font-semibold">{{ __('Dif. cambiaria') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-line">
                                                @forelse ($detalle as $renglon)
                                                    <tr>
                                                        <td class="whitespace-nowrap px-3 py-1.5 text-ink">{{ $renglon->number ?: $renglon->request_id }}</td>
                                                        <td class="whitespace-nowrap px-3 py-1.5 text-ink-muted">
                                                            {{ $renglon->date ? \Illuminate\Support\Carbon::parse($renglon->date)->format('d/m/Y') : '—' }}
                                                        </td>
                                                        <td class="whitespace-nowrap px-3 py-1.5 text-ink-muted">{{ $renglon->bank_name ?: '—' }}</td>
                                                        <td class="whitespace-nowrap px-3 py-1.5 text-ink-muted">{{ $renglon->prefix ?: '—' }}</td>
                                                        <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums text-ink-faint">
                                                            {{ $renglon->exchange_value === null ? '—' : number_format((float) $renglon->exchange_value, 4) }}
                                                        </td>
                                                        <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums text-ink-muted">{{ $money($renglon->amount_original_paid) }}</td>
                                                        <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums text-ink">{{ $money($renglon->total_paid) }}</td>
                                                        <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums {{ (float) $renglon->diference < 0 ? 'text-brand' : 'text-ink-muted' }}">
                                                            {{ $money($renglon->diference) }}
                                                        </td>
                                                    </tr>
                                                @empty
                                                    <tr>
                                                        <td colspan="8" class="px-3 py-6 text-center text-ink-faint">
                                                            {{ __('Sin solicitudes de pago en este renglón.') }}
                                                        </td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>

                                        @if (blank($datePay))
                                            <p class="mt-2 text-[11px] text-ink-faint">
                                                {{ __('La diferencia cambiaria necesita una fecha de revaluación: ponla arriba para compararla contra el tipo de cambio de ese día.') }}
                                            </p>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ count($columnas) + 2 }}" class="px-4 py-12 text-center text-ink-faint">
                                {{ __('No hay movimientos con estos filtros.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                @if ($filas->isNotEmpty())
                    <tfoot class="border-t border-line bg-panel text-sm font-semibold">
                        <tr>
                            <td class="px-4 py-2.5 text-ink-muted">{{ __('Total') }}</td>
                            @foreach ($columnas as [$etiqueta, $columna])
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-ink-soft">{{ $money($totales[$columna]) }}</td>
                            @endforeach
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums {{ $totalGeneral < 0 ? 'text-brand' : 'text-ink' }}">
                                {{ $money($totalGeneral) }}
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
