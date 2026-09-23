@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $esAdmin = auth()->user()?->isAdmin() ?? false;
    $esSuperAdmin = auth()->user()?->isSuperAdmin() ?? false;
    $desglose = [
        'sub_0_paid' => 'Sub 0 %',
        'sub_16_paid' => 'Sub 16 %',
        'tax_16_paid' => 'IVA 16 %',
        'non_dec' => __('No deducible'),
        'tax_ret_paid' => __('Ret. IVA'),
    ];
    // Al abrir una solicitud se va a su vista propia, llevándose el filtro actual
    // para que «Volver» regrese al mismo listado.
    $verUrl = fn ($id) => route('payments.requests.show', $id, absolute: false).'?volver='.urlencode($this->currentUrl());
@endphp

<div class="space-y-4">

    {{-- Se llegó desde un reporte de cobros y pagos: regreso con su filtro --}}
    @if ($volver !== '')
        <a href="{{ $volver }}" wire:navigate
           class="inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-ink">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            {{ __('Volver al reporte') }}
        </a>
    @endif

    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-ink">
                {{ __('Solicitudes de pago') }}
                @if ($contraparte)
                    <span class="text-ink-muted">· {{ $contraparte }}</span>
                @endif
            </h2>
            <p class="text-sm text-ink-muted">
                {{ __('Cada solicitud agrupa las transacciones que se cobran o se pagan juntas.') }}
            </p>
        </div>
        <span class="text-xs text-ink-faint">
            {{ number_format($filas->total()) }} solicitudes · consulta en {{ $queryMs }} ms
        </span>
    </header>

    @error('markPaid') <p class="alert-danger">{{ $message }}</p> @enderror

    {{-- Filtros --}}
    <div class="card p-4">
        <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-7">
            <label class="block">
                <span class="field-label text-xs">{{ __('Tipo') }}</span>
                <select wire:model.live="type" class="field-input mt-1 py-1.5 text-sm">
                    @foreach (['' => __('Todos'), '1' => __('Cobro a cliente'), '2' => __('Pago a proveedor')] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected((string) $valor === $type)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Folio') }}</span>
                <input type="text" inputmode="numeric" wire:model.live.debounce.400ms="folioId" value="{{ $folioId }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="0012">
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Número') }}</span>
                <input type="text" wire:model.live.debounce.400ms="number" value="{{ $number }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('exacto') }}">
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Estado') }}</span>
                <select wire:model.live="paid" class="field-input mt-1 py-1.5 text-sm">
                    @foreach (['' => __('Todas'), '0' => __('Pendientes'), '1' => __('Pagadas')] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected((string) $valor === $paid)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
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

            <div>
                <label class="block">
                    <span class="field-label text-xs">{{ __('Fechas') }} <span class="text-ink-faint">{{ __('(rango)') }}</span></span>
                    <input type="text" wire:model.live.debounce.600ms="dates" value="{{ $dates }}"
                           class="field-input mt-1 py-1.5 text-sm" placeholder="01/01/2025 - 31/12/2025">
                </label>
                {{-- El rango vigente: arranca en el año en curso para no traer toda la historia de golpe --}}
                <p class="mt-1 text-xs text-ink-faint">
                    {{ $dates !== '' ? __('Mostrando :rango', ['rango' => $dates]) : __('Mostrando todos los años') }}
                    @if ($dates !== '')
                        · <button type="button" wire:click="verTodosLosAnios" class="text-brand hover:underline">{{ __('Ver todos los años') }}</button>
                    @endif
                </p>
            </div>

            <label class="block">
                <span class="field-label text-xs">{{ __('Revaluar al TC del') }} <span class="text-ink-faint">{{ __('(dd/mm/aaaa)') }}</span></span>
                <input type="text" wire:model.live.debounce.600ms="datePay" value="{{ $datePay }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="{{ now()->format('d/m/Y') }}">
            </label>
        </div>

        <div class="mt-3 flex flex-wrap items-end gap-2">
            <button type="button" wire:click="clearFilters" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Limpiar filtros') }}</button>
            <x-totals-switch />
            <button type="button" wire:click="verTodas" wire:loading.attr="disabled" wire:target="verTodas"
                    class="ml-auto btn-ghost !py-1.5 !px-3 text-xs">
                <x-spinner wire:loading wire:target="verTodas" class="h-3.5 w-3.5" />
                {{ __('Ver todas') }}
            </button>
            <label class="flex items-center gap-2 text-xs text-ink-muted">
                {{ __('Por página') }}
                <select wire:model.live="perPage" class="field-input !w-auto py-1 text-xs">
                    @foreach ([25, 50, 100] as $n)
                        <option value="{{ $n }}" @selected($n === $perPage)>{{ $n }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>

    {{-- Listado --}}
    <div class="relative rounded-xl border border-line bg-panel">
        <div wire:loading.delay class="absolute inset-0 z-20 rounded-xl bg-panel/75 text-center backdrop-blur-[1px]">
            <span class="mt-14 inline-flex items-center gap-2 rounded-full border border-line bg-panel px-4 py-2 text-sm text-ink-muted shadow-lg">
                <x-spinner class="h-4 w-4 text-brand" />
                {{ __('Actualizando…') }}
            </span>
        </div>

        {{-- Tarjetas en móvil --}}
        <ul class="divide-y divide-line md:hidden">
            @forelse ($filas as $fila)
                <li class="cursor-pointer space-y-2 p-4 {{ (int) $fila->request_id === $highlight ? 'row-new' : '' }}"
                    x-data x-on:click="Livewire.navigate('{{ $verUrl($fila->request_id) }}')">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink">{{ $this->folio($fila->request_id) }}</p>
                            <p class="truncate text-sm text-ink-muted">
                                {{ (int) $fila->type === 1 ? ($fila->clientName ?: __('Sin cliente')) : ($fila->providerName ?: __('Sin proveedor')) }}
                            </p>
                        </div>
                        <span class="badge shrink-0 {{ $fila->paid ? 'badge-ok' : 'badge-warn' }}">
                            {{ $fila->paid ? __('Pagada') : __('Pendiente') }}
                        </span>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-faint">{{ __('Fecha') }}</dt>
                            <dd class="text-ink-soft">{{ $fila->date ? \Illuminate\Support\Carbon::parse($fila->date)->format('d/m/Y') : '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-faint">{{ __('Importe') }}</dt>
                            <dd class="font-semibold tabular-nums text-ink">{{ $money($fila->amount_original_neg) }} {{ $fila->prefix }}</dd>
                        </div>
                    </dl>
                </li>
            @empty
                <li class="px-4 py-12 text-center text-sm text-ink-faint">{{ __('No hay solicitudes con estos filtros.') }}</li>
            @endforelse
        </ul>

        {{-- Tabla desde md --}}
        <div class="hidden overflow-x-auto md:block">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line bg-panel text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Folio') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Tipo') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Asignada a') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Número') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Fecha') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Banco') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Divisa') }}</th>
                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('TC') }}</th>
                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('Importe') }}</th>
                        @foreach ($desglose as $etiqueta)
                            <th class="whitespace-nowrap px-3 py-2.5 text-right font-semibold">{{ $etiqueta }}</th>
                        @endforeach
                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('Total pagado') }}</th>
                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('TC pago') }}</th>
                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('Total a pagar') }}</th>
                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('Diferencia') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Estado') }}</th>
                        @if ($esAdmin)
                            <th class="px-3 py-2.5 text-right font-semibold"><span class="sr-only">{{ __('Acciones') }}</span></th>
                        @endif
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    @forelse ($filas as $fila)
                        @php $recienCreada = (int) $fila->request_id === $highlight; @endphp
                        <tr class="cursor-pointer transition {{ $recienCreada ? 'row-new' : 'hover:bg-raised' }}"
                            x-data x-on:click="Livewire.navigate('{{ $verUrl($fila->request_id) }}')">
                            <td class="whitespace-nowrap px-3 py-2">
                                <span class="font-medium text-brand hover:underline">{{ $this->folio($fila->request_id) }}</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ (int) $fila->type === 1 ? __('Cobro a cliente') : __('Pago a proveedor') }}</td>
                            <td class="max-w-[16rem] truncate px-3 py-2 text-ink-muted">
                                {{ (int) $fila->type === 1 ? ($fila->clientName ?: '—') : ($fila->providerName ?: '—') }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ $fila->number ?: '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">
                                {{ $fila->date ? \Illuminate\Support\Carbon::parse($fila->date)->format('d/m/Y') : '—' }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ $fila->bank_name ?: '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ $fila->prefix ?: '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-faint">
                                {{ $fila->exchange_value === null ? '—' : number_format((float) $fila->exchange_value, 4) }}
                            </td>
                            {{-- Con signo, como el «Amount» de Yii2: negativo en los pagos a proveedor. --}}
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-soft">{{ $money($fila->amount_original_neg) }}</td>
                            @foreach ($desglose as $columna => $etiqueta)
                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">{{ $money($fila->{$columna}) }}</td>
                            @endforeach
                            <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums {{ (float) $fila->total_paid < 0 ? 'text-brand' : 'text-ink' }}">
                                {{ $money($fila->total_paid) }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-faint">
                                {{ $fila->pay_tc === null ? '—' : number_format((float) $fila->pay_tc, 4) }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-soft">{{ $money($fila->total_to_pay) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">{{ $money($fila->diference) }}</td>
                            <td class="whitespace-nowrap px-3 py-2">
                                <span class="badge {{ $fila->paid ? 'badge-ok' : 'badge-warn' }}">
                                    {{ $fila->paid ? __('Pagada') : __('Pendiente') }}
                                </span>
                            </td>
                            @if ($esAdmin)
                                <td class="whitespace-nowrap px-3 py-2 text-right" wire:click.stop x-on:click.stop>
                                    <div class="flex justify-end gap-3 text-xs">
                                        <a href="{{ route('payments.requests.document', $fila->request_id) }}" target="_blank"
                                           class="text-ink-muted transition hover:text-brand">{{ __('Imprimir') }}</a>
                                        @if ($fila->paid)
                                            <button type="button" wire:click="reopen({{ $fila->request_id }})"
                                                    wire:confirm="{{ __('Reabrir la solicitud para poder corregirla. ¿Continuar?') }}"
                                                    class="text-brand hover:underline">{{ __('Reabrir') }}</button>
                                        @else
                                            <button type="button" wire:click="markPaid({{ $fila->request_id }})"
                                                    wire:confirm="{{ __('¿Marcar esta solicitud como pagada?') }}"
                                                    class="text-brand hover:underline">{{ __('Pagar') }}</button>
                                            @if ($esSuperAdmin)
                                                <button type="button" wire:click="delete({{ $fila->request_id }})"
                                                        wire:confirm="{{ __('Se borrará la solicitud y se soltarán sus transacciones. ¿Continuar?') }}"
                                                        class="text-ink-muted transition hover:text-brand">{{ __('Borrar') }}</button>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $esAdmin ? 20 : 19 }}" class="px-3 py-12 text-center text-ink-faint">
                                {{ __('No hay solicitudes con estos filtros.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                @if ($totals)
                    <tfoot class="border-t border-line bg-panel text-sm font-semibold">
                        <tr>
                            <td colspan="9" class="px-3 py-2.5 text-ink-muted">{{ __('Total del filtro completo') }} <span class="font-normal text-ink-faint">(MXN)</span></td>
                            @foreach (array_keys($desglose) as $columna)
                                <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-ink-soft">{{ $money($totals[$columna]) }}</td>
                            @endforeach
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-ink">{{ $money($totals['total_paid']) }}</td>
                            <td></td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-ink">{{ $money($totals['total_to_pay']) }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-ink-soft">{{ $money($totals['diference']) }}</td>
                            <td colspan="{{ $esAdmin ? 2 : 1 }}"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    <div>{{ $filas->links() }}</div>
</div>
