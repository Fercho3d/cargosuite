@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $fecha = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
    $esCliente = $this->isClient();
@endphp

<div class="space-y-4">

    <a href="{{ route('portal') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-ink-muted hover:text-ink">
        &larr; {{ __('Volver') }}
    </a>

    @if (session('status'))
        <p class="alert-ok">{{ session('status') }}</p>
    @endif

    <header class="card p-5">
        <h2 class="text-lg font-semibold text-ink">
            {{ $documento->tran_number ?: __('Sin número') }}
        </h2>
        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
            @foreach ([
                [__('Booking'), trim((string) $documento->booking_number) ?: '—'],
                [__('Fecha'), $fecha($documento->tran_date)],
                [__('Moneda'), $documento->currency ?: '—'],
                [__('Total'), $money($documento->total_amount)],
            ] as [$etiqueta, $valor])
                <div>
                    <dt class="text-xs text-ink-faint">{{ $etiqueta }}</dt>
                    <dd class="tabular-nums text-ink-soft">{{ $valor }}</dd>
                </div>
            @endforeach
        </dl>
    </header>

    {{-- Conceptos: los captura la operación, el portal solo los enseña. --}}
    <section class="card overflow-hidden">
        <h3 class="border-b border-line px-5 py-3 text-sm font-semibold text-ink">{{ __('Conceptos') }}</h3>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-5 py-2.5 text-left font-semibold">{{ __('Descripción') }}</th>
                        <th class="px-5 py-2.5 text-right font-semibold">{{ __('Cantidad') }}</th>
                        <th class="px-5 py-2.5 text-right font-semibold">{{ __('Precio') }}</th>
                        <th class="px-5 py-2.5 text-right font-semibold">{{ __('Importe') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($conceptos as $concepto)
                        <tr>
                            <td class="px-5 py-2 text-ink-soft">{{ $concepto->description ?: ($concepto->chargeType?->charge_type_name ?: '—') }}</td>
                            <td class="px-5 py-2 text-right tabular-nums text-ink-muted">{{ number_format((float) $concepto->quantity, 2) }}</td>
                            <td class="px-5 py-2 text-right tabular-nums text-ink-muted">{{ $money($concepto->price) }}</td>
                            <td class="px-5 py-2 text-right tabular-nums text-ink-soft">{{ $money($concepto->total) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-10 text-center text-ink-faint">{{ __('Este documento no tiene conceptos.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Archivos: el cliente solo descarga; el proveedor sube los suyos. --}}
    <section class="card p-5">
        <h3 class="text-sm font-semibold text-ink">{{ __('Archivos') }}</h3>

        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ([['pdf', 'PDF', $documento->pdf_attach], ['xml', 'XML', $documento->xml_attach]] as [$clase, $etiqueta, $archivo])
                @if ($archivo)
                    <a href="{{ route('portal.file', [$documento->transc_id, $clase]) }}"
                       class="btn-ghost px-3 py-1.5 text-xs">{{ $etiqueta }}</a>
                @else
                    <span class="rounded-lg border border-dashed border-line px-3 py-1.5 text-xs text-ink-faint">
                        {{ __('Sin').' '.$etiqueta }}
                    </span>
                @endif
            @endforeach
        </div>

        @if (! $esCliente)
            @if ($this->isUploadable())
                <form wire:submit="save" class="mt-5 space-y-4 border-t border-line pt-5">
                    <p class="text-sm text-ink-muted">
                        {{ __('Sube aquí tu factura. Los importes ya están registrados; tú anotas con qué número y fecha la emitiste.') }}
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="field-label">{{ __('Número de tu factura') }}</span>
                            <input type="text" wire:model="tranNumber" value="{{ $tranNumber }}" maxlength="50" class="field-input mt-1.5" required>
                            @error('tranNumber') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="field-label">{{ __('Fecha de emisión') }}</span>
                            <input type="date" wire:model="tranDate" value="{{ $tranDate }}" class="field-input mt-1.5" required>
                            @error('tranDate') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="field-label">{{ __('Factura en PDF') }}</span>
                            <input type="file" wire:model="pdf" accept="application/pdf" class="field-input mt-1.5">
                            @error('pdf') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="field-label">{{ __('XML del CFDI') }}</span>
                            <input type="file" wire:model="xml" accept="text/xml,application/xml,.xml" class="field-input mt-1.5">
                            @error('xml') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                        </label>
                    </div>

                    <x-submit-button>{{ __('Guardar') }}</x-submit-button>
                </form>
            @else
                <p class="mt-4 border-t border-line pt-4 text-sm text-ink-faint">
                    {{ __('Este documento ya no se puede modificar.') }}
                </p>
            @endif
        @endif
    </section>
</div>
