@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $esAdmin = auth()->user()?->isAdmin() ?? false;
@endphp

<div class="space-y-4">

    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-ink">{{ __('Solicitudes de pago') }}</h2>
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
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <label class="block">
                <span class="field-label text-xs">{{ __('Número') }}</span>
                <input type="text" wire:model.live.debounce.400ms="number" value="{{ $number }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('exacto') }}">
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Tipo') }}</span>
                <select wire:model.live="type" class="field-input mt-1 py-1.5 text-sm">
                    @foreach (['' => __('Todos'), '1' => __('Cobros a clientes'), '2' => __('Pagos a proveedores')] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected((string) $valor === $type)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
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

            <label class="block">
                <span class="field-label text-xs">{{ __('Fechas') }} <span class="text-ink-faint">{{ __('(rango)') }}</span></span>
                <input type="text" wire:model.live.debounce.600ms="dates" value="{{ $dates }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="01/01/2025 - 31/12/2025">
            </label>
        </div>

        <div class="mt-3 flex flex-wrap items-end gap-2">
            <button type="button" wire:click="clearFilters" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Limpiar filtros') }}</button>
            <label class="ml-auto flex items-center gap-2 text-xs text-ink-muted">
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
                <li class="space-y-2 p-4 {{ (int) $fila->request_id === $highlight ? 'row-new' : '' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink">{{ $this->folio($fila->request_id) }}</p>
                            <p class="truncate text-sm text-ink-muted">
                                {{ (int) $fila->type === 1 ? ($fila->clientName ?: __('Sin cliente')) : ($fila->providerName ?: __('Sin proveedor')) }}
                            </p>
                        </div>
                        <span class="badge shrink-0 {{ $fila->paid ? 'badge-ok' : 'badge-warn' }}">
                            {{ $fila->paid ? 'Pagada' : 'Pendiente' }}
                        </span>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-faint">{{ __('Fecha') }}</dt>
                            <dd class="text-ink-soft">{{ $fila->date ? \Illuminate\Support\Carbon::parse($fila->date)->format('d/m/Y') : '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-faint">{{ __('Importe') }}</dt>
                            <dd class="font-semibold tabular-nums text-ink">{{ $money($fila->amount) }} {{ $fila->prefix }}</dd>
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
                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('Total pagado') }}</th>
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
                            wire:click="toggle({{ $fila->request_id }})">
                            <td class="whitespace-nowrap px-3 py-2">
                                <span class="inline-flex items-center gap-2">
                                    <svg class="h-3.5 w-3.5 shrink-0 text-ink-faint transition {{ $expanded === (int) $fila->request_id ? 'rotate-90' : '' }}"
                                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                    </svg>
                                    <span class="font-medium text-ink">{{ $this->folio($fila->request_id) }}</span>
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ (int) $fila->type === 1 ? 'Cobro' : 'Pago' }}</td>
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
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-soft">{{ $money($fila->amount) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums {{ (float) $fila->total_paid < 0 ? 'text-brand' : 'text-ink' }}">
                                {{ $money($fila->total_paid) }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2">
                                <span class="badge {{ $fila->paid ? 'badge-ok' : 'badge-warn' }}">
                                    {{ $fila->paid ? 'Pagada' : 'Pendiente' }}
                                </span>
                            </td>
                            @if ($esAdmin)
                                <td class="whitespace-nowrap px-3 py-2 text-right" wire:click.stop>
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
                                            <button type="button" wire:click="delete({{ $fila->request_id }})"
                                                    wire:confirm="{{ __('Se borrará la solicitud y se soltarán sus transacciones. ¿Continuar?') }}"
                                                    class="text-ink-muted transition hover:text-brand">{{ __('Borrar') }}</button>
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>

                        {{-- Transacciones que agrupa --}}
                        @if ($expanded === (int) $fila->request_id)
                            <tr>
                                <td colspan="{{ $esAdmin ? 12 : 11 }}" class="bg-raised/50 p-0">
                                    <div class="overflow-x-auto p-4">
                                        <table class="min-w-full text-xs">
                                            <thead class="text-[11px] uppercase tracking-wide text-ink-faint">
                                                <tr>
                                                    <th class="px-3 py-1.5 text-left font-semibold">{{ __('Transacción') }}</th>
                                                    <th class="px-3 py-1.5 text-left font-semibold">{{ __('Booking') }}</th>
                                                    <th class="px-3 py-1.5 text-left font-semibold">{{ __('Fecha') }}</th>
                                                    <th class="px-3 py-1.5 text-right font-semibold">{{ __('Total') }}</th>
                                                    <th class="px-3 py-1.5 text-right font-semibold">{{ __('Pagado') }}</th>
                                                    <th class="px-3 py-1.5 text-right font-semibold">{{ __('Por pagar') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-line">
                                                @forelse ($transacciones as $t)
                                                    <tr>
                                                        <td class="whitespace-nowrap px-3 py-1.5">
                                                            <a href="{{ route('transactions.show', $t->transc_id) }}" wire:navigate
                                                               class="text-brand hover:underline">{{ $t->tran_number ?: $t->transc_id }}</a>
                                                        </td>
                                                        <td class="whitespace-nowrap px-3 py-1.5 text-ink-muted">{{ trim((string) $t->booking_number) ?: '—' }}</td>
                                                        <td class="whitespace-nowrap px-3 py-1.5 text-ink-muted">
                                                            {{ $t->tran_date ? \Illuminate\Support\Carbon::parse($t->tran_date)->format('d/m/Y') : '—' }}
                                                        </td>
                                                        <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums text-ink-soft">{{ $money($t->total_natural_amount) }}</td>
                                                        <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums text-ink-muted">{{ $money($t->tran_paid_amount) }}</td>
                                                        <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums text-ink">{{ $money($t->left_to_pay) }}</td>
                                                    </tr>
                                                @empty
                                                    <tr>
                                                        <td colspan="6" class="px-3 py-6 text-center text-ink-faint">
                                                            {{ __('Esta solicitud no tiene transacciones.') }}
                                                        </td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ $esAdmin ? 12 : 11 }}" class="px-3 py-12 text-center text-ink-faint">
                                {{ __('No hay solicitudes con estos filtros.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>{{ $filas->links() }}</div>
</div>
