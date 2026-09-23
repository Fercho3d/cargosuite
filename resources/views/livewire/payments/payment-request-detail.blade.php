@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $esAdmin = auth()->user()?->isAdmin() ?? false;
    $esSuperAdmin = auth()->user()?->isSuperAdmin() ?? false;
    $esCobro = (int) $solicitud->type === 1;
    // $editable (admin y no pagada) y $tcPropio llegan del componente.
@endphp

<div class="space-y-4">

    {{-- Volver + acciones --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ $volver }}" wire:navigate class="btn-ghost !px-3 !py-1.5 text-sm">
            <svg class="mr-1 inline h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
            {{ __('Volver') }}
        </a>

        @if ($esAdmin)
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('payments.requests.document', $solicitud->request_id) }}" target="_blank"
                   class="btn-ghost !px-3 !py-1.5 text-sm">{{ __('Imprimir') }}</a>
                @if ($solicitud->paid)
                    <button type="button" wire:click="reopen"
                            wire:confirm="{{ __('Reabrir la solicitud para poder corregirla. ¿Continuar?') }}"
                            class="btn-ghost !px-3 !py-1.5 text-sm">{{ __('Reabrir') }}</button>
                @else
                    @if ($esSuperAdmin)
                        <button type="button" wire:click="delete"
                                wire:confirm="{{ __('Se borrará la solicitud y se soltarán sus transacciones. ¿Continuar?') }}"
                                class="btn-ghost !px-3 !py-1.5 text-sm">{{ __('Borrar') }}</button>
                    @endif
                    <button type="button" wire:click="save" class="btn-ghost !px-3 !py-1.5 text-sm">{{ __('Guardar cambios') }}</button>
                    <button type="button" wire:click="markPaid"
                            wire:confirm="{{ __('¿Marcar esta solicitud como pagada?') }}"
                            class="btn-accent !px-3 !py-1.5 text-sm">{{ __('Pagar') }}</button>
                @endif
            </div>
        @endif
    </div>

    @if (session('status'))
        <p class="rounded-lg border border-line bg-panel px-4 py-2 text-sm text-ink-soft">{{ session('status') }}</p>
    @endif

    {{-- Encabezado de la solicitud --}}
    <div class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-ink">{{ __('Solicitud') }} {{ $this->folio($solicitud->request_id) }}</h2>
                <p class="text-sm text-ink-muted">
                    {{ $esCobro ? __('Cobro a cliente') : __('Pago a proveedor') }} ·
                    {{ $esCobro ? ($solicitud->clientName ?: __('Sin cliente')) : ($solicitud->providerName ?: __('Sin proveedor')) }}
                </p>
            </div>
            <span class="badge {{ $solicitud->paid ? 'badge-ok' : 'badge-warn' }}">
                {{ $solicitud->paid ? __('Pagada') : __('Pendiente') }}
            </span>
        </div>

        <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div class="flex justify-between gap-2 border-b border-line pb-2">
                <dt class="text-ink-faint">{{ __('Número') }}</dt>
                @if ($editable)
                    <dd><input type="text" wire:model="number" maxlength="64" class="field-input !w-44 py-1 text-sm">
                        @error('number') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror</dd>
                @else
                    <dd class="text-ink-soft">{{ $solicitud->number ?: '—' }}</dd>
                @endif
            </div>
            <div class="flex justify-between gap-2 border-b border-line pb-2">
                <dt class="text-ink-faint">{{ __('Fecha') }}</dt>
                @if ($editable)
                    <dd><input type="date" wire:model="date" class="field-input !w-44 py-1 text-sm">
                        @error('date') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror</dd>
                @else
                    <dd class="text-ink-soft">{{ $solicitud->date ? \Illuminate\Support\Carbon::parse($solicitud->date)->format('d/m/Y') : '—' }}</dd>
                @endif
            </div>
            <div class="flex justify-between gap-2 border-b border-line pb-2">
                <dt class="text-ink-faint">{{ __('Banco') }}</dt>
                @if ($editable)
                    <dd><select wire:model="bankId" class="field-input !w-44 py-1 text-sm">
                            <option value="">—</option>
                            @foreach ($banks as $id => $etiqueta)
                                <option value="{{ $id }}">{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                        @error('bankId') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror</dd>
                @else
                    <dd class="text-ink-soft">{{ $solicitud->bank_name ?: '—' }}</dd>
                @endif
            </div>
            <div class="flex justify-between gap-2 border-b border-line pb-2">
                <dt class="text-ink-faint">{{ __('Divisa') }}</dt>
                <dd class="text-ink-soft">{{ $solicitud->prefix ?: '—' }}</dd>
            </div>
            <div class="flex justify-between gap-2 border-b border-line pb-2">
                <dt class="text-ink-faint">{{ __('TC') }}</dt>
                <dd class="tabular-nums text-ink-soft">
                    {{ $solicitud->exchange_value === null ? '—' : number_format((float) $solicitud->exchange_value, 4) }}
                    @if ($tcPropio)
                        <span class="badge badge-warn ml-1" title="{{ __('Tipo de cambio propio') }}">{{ __('propio') }}</span>
                    @endif
                </dd>
            </div>
            <div class="flex justify-between gap-2 border-b border-line pb-2">
                <dt class="text-ink-faint">{{ __('Importe') }}</dt>
                <dd class="font-semibold tabular-nums text-ink">{{ $money($solicitud->amount) }} {{ $solicitud->prefix }}</dd>
            </div>
            <div class="flex justify-between gap-2 border-b border-line pb-2">
                <dt class="text-ink-faint">{{ __('Total pagado') }}</dt>
                <dd class="font-semibold tabular-nums {{ (float) $solicitud->total_paid < 0 ? 'text-brand' : 'text-ink' }}">{{ $money($solicitud->total_paid) }}</dd>
            </div>
        </dl>
    </div>

    {{-- Transacciones que agrupa --}}
    <div class="rounded-xl border border-line bg-panel">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
            <h3 class="text-sm font-semibold text-ink">{{ __('Transacciones que agrupa') }}</h3>
            @if ($editable)
                <button type="button" wire:click="toggleAdd" class="btn-ghost !px-3 !py-1.5 text-sm">
                    {{ $showAdd ? __('Cerrar') : __('Agregar transacción') }}
                </button>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-[11px] uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Transacción') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Booking') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Fecha') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('Total') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('Pagado') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('Por pagar') }}</th>
                        @if ($editable)
                            <th class="px-3 py-2 text-right font-semibold">{{ __('Se aplica') }}</th>
                            <th class="px-3 py-2"><span class="sr-only">{{ __('Acciones') }}</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($transacciones as $t)
                        <tr class="hover:bg-raised">
                            <td class="whitespace-nowrap px-3 py-2">
                                <a href="{{ route('transactions.show', $t->transc_id) }}" wire:navigate
                                   class="text-brand hover:underline">{{ $t->tran_number ?: $t->transc_id }}</a>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ trim((string) $t->booking_number) ?: '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">
                                {{ $t->tran_date ? \Illuminate\Support\Carbon::parse($t->tran_date)->format('d/m/Y') : '—' }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-soft">{{ $money($t->total_natural_amount) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">{{ $money($t->tran_paid_amount) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink">{{ $money($t->left_to_pay) }}</td>
                            @if ($editable)
                                <td class="whitespace-nowrap px-3 py-2 text-right">
                                    <input type="number" step="0.01" wire:model="amounts.{{ $t->transc_id }}"
                                           class="field-input !w-32 py-1 text-right text-sm tabular-nums">
                                    @error('amounts.'.$t->transc_id)
                                        <span class="mt-1 block text-xs text-brand">{{ $message }}</span>
                                    @enderror
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right text-xs">
                                    @if (count($amounts) > 1)
                                        <button type="button" wire:click="removeTransaction({{ $t->transc_id }})"
                                                wire:confirm="{{ __('¿Quitar esta transacción de la solicitud?') }}"
                                                class="text-ink-muted transition hover:text-brand">{{ __('Quitar') }}</button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-10 text-center text-ink-faint">{{ __('Esta solicitud no tiene transacciones.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Agregar transacción: el «Add» del modal de Yii2, con su selector --}}
    @if ($editable && $showAdd)
        <div class="rounded-xl border border-line bg-panel" wire:key="panel-agregar">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
                <div>
                    <h3 class="text-sm font-semibold text-ink">{{ __('Agregar transacción') }}</h3>
                    <p class="text-xs text-ink-muted">
                        {{ $esCobro
                            ? __('Facturas del mismo cliente y divisa con saldo pendiente.')
                            : __('Costos del mismo proveedor y divisa con saldo pendiente.') }}
                    </p>
                </div>
                <input type="search" wire:model.live.debounce.300ms="addSearch"
                       placeholder="{{ __('Buscar por número o booking…') }}"
                       class="field-input !w-full py-1.5 text-sm sm:!w-72">
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-[11px] uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold">{{ __('Transacción') }}</th>
                            <th class="px-3 py-2 text-left font-semibold">{{ __('Booking') }}</th>
                            <th class="px-3 py-2 text-left font-semibold">{{ __('Fecha') }}</th>
                            <th class="px-3 py-2 text-right font-semibold">{{ __('Total') }}</th>
                            <th class="px-3 py-2 text-right font-semibold">{{ __('Pagado') }}</th>
                            <th class="px-3 py-2 text-right font-semibold">{{ __('Por pagar') }}</th>
                            <th class="px-3 py-2"><span class="sr-only">{{ __('Acciones') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($candidatas as $t)
                            <tr class="hover:bg-raised" wire:key="candidata-{{ $t->transc_id }}">
                                <td class="whitespace-nowrap px-3 py-2">
                                    <a href="{{ route('transactions.show', $t->transc_id) }}" wire:navigate
                                       class="text-brand hover:underline">{{ $t->tran_number ?: $t->transc_id }}</a>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ trim((string) $t->booking_number) ?: '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-ink-muted">
                                    {{ $t->tran_date ? \Illuminate\Support\Carbon::parse($t->tran_date)->format('d/m/Y') : '—' }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-soft">{{ $money($t->total_natural_amount) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">{{ $money($t->tran_paid_amount) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink">{{ $money($t->left_to_pay) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right text-xs">
                                    <button type="button" wire:click="addTransaction({{ $t->transc_id }})"
                                            wire:loading.attr="disabled" wire:target="addTransaction({{ $t->transc_id }})"
                                            class="text-brand transition hover:underline">{{ __('Agregar') }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-10 text-center text-ink-faint">
                                    {{ trim($addSearch) !== ''
                                        ? __('Ninguna transacción coincide con la búsqueda.')
                                        : __('No hay transacciones pendientes que se puedan agregar.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($candidatasTotal > $maxCandidatas)
                <p class="border-t border-line px-4 py-2 text-xs text-ink-muted">
                    {{ __('Se muestran las primeras :n de :total; afina la búsqueda.', ['n' => $maxCandidatas, 'total' => $candidatasTotal]) }}
                </p>
            @endif
        </div>
    @endif
</div>
