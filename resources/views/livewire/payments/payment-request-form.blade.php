@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $esCobro = $this->isCollection();
    $primera = $transacciones->first();
@endphp

<div class="mx-auto max-w-4xl space-y-4">

    <a href="{{ $this->backUrl() }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-ink">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        {{ __('Volver al listado') }}
    </a>

    <form wire:submit="save" class="card space-y-5 p-5 sm:p-6">
        <header>
            <h2 class="text-lg font-semibold text-ink">
                {{ $esCobro ? __('Nueva solicitud de cobro') : __('Nueva solicitud de pago') }}
            </h2>
            <p class="mt-0.5 text-sm text-ink-muted">
                {{ $transacciones->count() }} {{ $transacciones->count() === 1 ? __('transacción') : __('transacciones') }}
                · {{ $esCobro ? ($primera?->customerName ?: __('Sin cliente')) : ($primera?->vendorName ?: __('Sin proveedor')) }}
                · {{ $primera?->currency ?: '—' }}
            </p>
        </header>

        @error('seleccion') <p class="alert-danger">{{ $message }}</p> @enderror

        <div class="grid gap-4 sm:grid-cols-3">
            <label class="block">
                <span class="field-label">{{ __('Número') }}</span>
                <input type="text" wire:model="number" value="{{ $number }}" maxlength="64" class="field-input mt-1.5" required>
                @error('number') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="field-label">{{ __('Fecha') }}</span>
                <input type="date" wire:model="date" value="{{ $date }}" class="field-input mt-1.5" required>
                @error('date') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="field-label">{{ __('Banco') }}</span>
                <select wire:model="bankId" class="field-input mt-1.5" required>
                    <option value="">{{ __('Selecciona el banco') }}</option>
                    @foreach ($banks as $id => $etiqueta)
                        <option value="{{ $id }}" @selected((string) $id === $bankId)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
                @error('bankId') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
            </label>
        </div>

        {{-- Tipo de cambio: el del día como referencia o uno propio (la casilla «Custom TC» de Yii2) --}}
        <div class="flex flex-wrap items-end gap-4">
            <label class="flex items-center gap-2 text-sm text-ink-soft">
                <input type="checkbox" wire:model.live="customTc" class="rounded border-line text-brand focus:ring-brand">
                {{ __('Tipo de cambio propio') }}
            </label>
            @if ($customTc)
                <label class="block">
                    <span class="field-label text-xs">{{ __('Tipo de cambio') }}</span>
                    <input type="number" step="0.0001" min="0" wire:model="tcValue" value="{{ $tcValue }}"
                           class="field-input mt-1 !w-36 py-1.5 text-right text-sm tabular-nums" placeholder="0.0000">
                    @error('tcValue') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                </label>
            @else
                @php $tcDelDia = $this->dayRate(); @endphp
                <span class="text-sm text-ink-muted">
                    {{ __('Tipo de cambio') }}:
                    <span class="tabular-nums text-ink-soft">{{ $tcDelDia === null ? '—' : number_format($tcDelDia, 4) }}</span>
                    @if ($tcDelDia === null)
                        <span class="text-xs text-ink-faint">{{ __('(se registra el del día al guardar)') }}</span>
                    @endif
                </span>
            @endif
        </div>

        {{-- Transacciones y el importe que se aplica a cada una --}}
        <div class="overflow-x-auto rounded-xl border border-line">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Transacción') }}</th>
                        <th class="px-3 py-2 text-left font-semibold">{{ __('Booking') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('Total') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('Pagado') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('Por pagar') }}</th>
                        <th class="px-3 py-2 text-right font-semibold">{{ __('Se aplica') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($transacciones as $t)
                        <tr>
                            <td class="whitespace-nowrap px-3 py-2 text-ink">{{ $t->tran_number ?: $t->transc_id }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ trim((string) $t->booking_number) ?: '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">{{ $money($t->total_natural_amount) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">{{ $money($t->tran_paid_amount) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-soft">{{ $money($t->left_to_pay) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right">
                                <input type="number" step="0.01" wire:model.live="amounts.{{ $t->transc_id }}"
                                       value="{{ $amounts[$t->transc_id] ?? '' }}"
                                       class="field-input !w-32 py-1 text-right text-sm tabular-nums">
                                @error('amounts.'.$t->transc_id)
                                    <span class="mt-1 block text-xs text-brand">{{ $message }}</span>
                                @enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t border-line bg-panel text-sm font-semibold">
                    <tr>
                        <td colspan="5" class="px-3 py-2.5 text-right text-ink-muted">{{ __('Importe de la solicitud') }}</td>
                        <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-ink">{{ $money($this->total()) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <footer class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-4">
            <a href="{{ $this->backUrl() }}" wire:navigate class="btn-ghost">{{ __('Cancelar') }}</a>
            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="btn-accent">
                <x-spinner wire:loading wire:target="save" class="h-4 w-4" />
                {{ __('Crear solicitud') }}
            </button>
        </footer>
    </form>
</div>
