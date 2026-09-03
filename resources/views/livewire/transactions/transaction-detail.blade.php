@use('App\Support\PaymentStatus')
@use('App\Models\Core\Transaction')

@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $estado = PaymentStatus::for($fila);
    $esFactura = (int) $fila->tran_type === Transaction::TYPE_INVOICE;
    $contraparte = $esFactura ? $fila->customerName : $fila->vendorName;

    // El desglose del pie se toma de las mismas columnas que alimentan la tabla,
    // para que los importes cuadren al centavo con el listado.
    $desglose = [
        ['Subtotal 0 %', $fila->sub_0_mxn],
        ['Subtotal 16 %', $fila->sub_16_mxn],
        ['IVA 16 %', $fila->tax_16_mxn],
        [__('Retención IVA'), $fila->tax_ret_mxn],
    ];
@endphp

<div class="mx-auto max-w-5xl space-y-4">

    {{-- Regreso al listado --}}
    <a href="{{ route($esFactura ? 'transactions.invoice' : 'transactions.bill') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-ink">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        Volver a {{ $esFactura ? __('Facturas') : __('Costos') }}
    </a>

    {{-- Encabezado --}}
    <section class="card p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-faint">
                    {{ Transaction::typeText($fila->invoice_type, $fila->tran_type) }}
                </p>
                <h2 class="mt-0.5 truncate text-2xl font-semibold text-ink">
                    {{ $fila->tran_number ?: __('Sin número') }}
                </h2>
                <p class="mt-1 truncate text-sm text-ink-muted">{{ $contraparte ?: '—' }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if (auth()->user()?->isAdmin())
                    <a href="{{ route('transactions.edit', $fila->transc_id) }}" wire:navigate class="btn-ghost px-3 py-1.5 text-xs">
                        {{ __('Editar') }}
                    </a>
                @endif
                @if ($this->canStamp())
                    <button type="button" wire:click="stamp" wire:loading.attr="disabled" wire:target="stamp"
                            wire:confirm="{{ __('Se timbrará esta factura ante el SAT. Esta operación no se puede deshacer sin cancelarla. ¿Continuar?') }}"
                            class="btn-accent px-3 py-1.5 text-xs">
                        <x-spinner wire:loading wire:target="stamp" class="h-3.5 w-3.5" />
                        {{ __('Timbrar') }}
                    </button>
                @endif
                @if ($transaccion->seal && auth()->user()?->isAdmin())
                    <button type="button" wire:click="resend" wire:loading.attr="disabled" wire:target="resend"
                            wire:confirm="{{ __('Se le volverá a mandar al cliente la factura con su PDF y su XML. ¿Continuar?') }}"
                            class="btn-ghost px-3 py-1.5 text-xs">
                        <x-spinner wire:loading wire:target="resend" class="h-3.5 w-3.5" />
                        {{ __('Reenviar al cliente') }}
                    </button>
                @endif
                @if ($this->canCancel())
                    <button type="button" wire:click="startCancel" class="btn-ghost px-3 py-1.5 text-xs text-brand">
                        {{ __('Cancelar CFDI') }}
                    </button>
                @endif
                @if ($sePuedeBorrar)
                    <button type="button" wire:click="deleteTransaction"
                            wire:confirm="{{ __('Se borrará la transacción y todos sus conceptos. ¿Continuar?') }}"
                            class="btn-ghost px-3 py-1.5 text-xs text-brand">{{ __('Borrar') }}</button>
                @endif
                <span class="{{ $estado->classes() }}">{{ $estado->label() }}</span>
                @if ($fila->cancelled)
                    <span class="badge badge-danger">{{ __('Cancelada') }}</span>
                @endif
                @if (filled($fila->seal))
                    <span class="badge badge-ok">{{ __('Timbrada') }}</span>
                @endif
            </div>
        </div>

        @error('cfdi')
            <p class="alert-danger mt-4">{{ $message }}</p>
        @enderror

        @if ($cancelling)
            <form wire:submit="cancelStamp" class="mt-4 space-y-3 rounded-xl border border-line bg-raised/60 p-4">
                <p class="text-sm font-medium text-ink">{{ __('Cancelar el CFDI ante el SAT') }}</p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="field-label text-xs">{{ __('Motivo') }}</span>
                        <select wire:model.live="cancelReason" class="field-input mt-1 py-1.5 text-sm">
                            @foreach ($motivosCancelacion as $clave => $etiqueta)
                                <option value="{{ $clave }}" @selected($clave === $cancelReason)>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </label>

                    @if ($cancelReason === '01')
                        <label class="block">
                            <span class="field-label text-xs">{{ __('Folio fiscal que la sustituye') }}</span>
                            <input type="text" wire:model="replacementUuid" value="{{ $replacementUuid }}"
                                   class="field-input mt-1 py-1.5 text-sm font-mono" placeholder="{{ __('UUID') }}">
                        </label>
                    @endif
                </div>

                <p class="text-xs text-ink-faint">
                    {{ __('El motivo 01 exige el folio del comprobante que sustituye a este. La cancelación se solicita al mismo PAC que lo timbró.') }}
                </p>

                <div class="flex flex-wrap justify-end gap-3">
                    <button type="button" wire:click="$set('cancelling', false)" class="btn-ghost !px-3 !py-1.5 text-xs">Cerrar</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="cancelStamp"
                            class="btn-accent !px-3 !py-1.5 text-xs">
                        <x-spinner wire:loading wire:target="cancelStamp" class="h-3.5 w-3.5" />
                        {{ __('Cancelar ante el SAT') }}
                    </button>
                </div>
            </form>
        @endif

        @if ($candado->locked)
            <p class="mt-4 flex items-start gap-2 rounded-lg border border-line bg-raised px-3 py-2 text-xs text-ink-muted">
                <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <rect x="5" y="11" width="14" height="9" rx="2"/><path stroke-linecap="round" d="M8 11V8a4 4 0 0 1 8 0v3"/>
                </svg>
                <span>{{ $candado->reason }} {{ __('Solo la compañía emisora puede modificarse.') }}</span>
            </p>
        @endif

        <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-4 border-t border-line pt-5 text-sm sm:grid-cols-3 lg:grid-cols-4">
            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-faint">{{ __('Booking') }}</dt>
                <dd class="mt-0.5">
                    <a href="{{ route('transactions.booking', $fila->booking) }}" wire:navigate
                       class="font-medium text-brand hover:underline">
                        {{ trim((string) $fila->booking_number) ?: '—' }}
                    </a>
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-faint">{{ __('Fecha') }}</dt>
                <dd class="mt-0.5 text-ink">
                    {{ $fila->tran_date ? \Illuminate\Support\Carbon::parse($fila->tran_date)->format('d/m/Y') : '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-faint">{{ __('Compañía') }}</dt>
                <dd class="mt-0.5 truncate text-ink" title="{{ $fila->companyName }}">{{ $fila->companyName ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-faint">{{ __('Moneda') }}</dt>
                <dd class="mt-0.5 text-ink">
                    {{ $fila->currency ?: '—' }}
                    <span class="text-ink-faint">
                        @ {{ $fila->exchange_value === null ? '—' : number_format((float) $fila->exchange_value, 4) }}
                    </span>
                </dd>
            </div>
            @if (filled($fila->seal))
                <div class="col-span-2 min-w-0 sm:col-span-3 lg:col-span-4">
                    <dt class="text-xs uppercase tracking-wide text-ink-faint">{{ __('Sello CFDI') }}</dt>
                    <dd class="mt-0.5 break-all font-mono text-xs text-ink-soft">{{ $fila->seal }}</dd>
                </div>
            @endif
        </dl>

        @if ($fila->pdf_attach || $fila->xml_attach)
            <div class="mt-5 flex flex-wrap gap-2 border-t border-line pt-4">
                @foreach ([['pdf', $fila->pdf_attach], ['xml', $fila->xml_attach]] as [$tipo, $archivo])
                    @if ($archivo)
                        <a href="{{ route('transactions.file', [$fila->transc_id, $tipo]) }}" target="_blank"
                           class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-line px-3 py-1.5 text-xs text-ink-muted transition hover:bg-raised hover:text-ink">
                            <span class="font-semibold uppercase text-ink-faint">{{ $tipo }}</span>
                            <span class="max-w-[14rem] truncate">{{ $archivo }}</span>
                        </a>
                    @endif
                @endforeach
            </div>
        @endif
    </section>

    {{-- Conceptos --}}
    <section class="card overflow-hidden">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
            <h3 class="text-sm font-semibold text-ink">{{ __('Conceptos') }}</h3>

            <div class="flex items-center gap-3">
                <span class="text-xs text-ink-faint">
                    {{ $cargos->count() }} {{ \Illuminate\Support\Str::plural('línea', $cargos->count()) }}
                </span>
                @unless ($candado->locked)
                    <button type="button" wire:click="addCharge" class="btn-ghost px-3 py-1.5 text-xs">
                        {{ __('Agregar concepto') }}
                    </button>
                @endunless
            </div>
        </header>

        {{-- Alta y edición de un concepto --}}
        @if ($editingCharge)
            <form wire:submit="saveCharge" class="space-y-4 border-b border-line bg-raised/60 p-5">
                <p class="text-sm font-medium text-ink">
                    {{ $chargeId ? __('Editar concepto') : __('Nuevo concepto') }}
                </p>

                @if ($tiposDeCargo === [])
                    <p class="alert-danger">
                        {{ __('No hay servicios contratados con :tercero, así que no hay tipos de cargo que ofrecer. Da de alta el servicio en el catálogo primero.', [
                            'tercero' => $esFactura ? __('este cliente') : __('este proveedor'),
                        ]) }}
                    </p>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="field-label">{{ __('Tipo de cargo') }}</span>
                        <select wire:model.live="chargeType" class="field-input mt-1.5" required>
                            <option value="">{{ __('Selecciona el tipo') }}</option>
                            @foreach ($tiposDeCargo as $id => $etiqueta)
                                <option value="{{ $id }}" @selected((string) $id === $chargeType)>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                        @error('chargeType') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="field-label">{{ __('Servicio') }}</span>
                        <select wire:model.live="serviceId" class="field-input mt-1.5"
                                @disabled($chargeType === '') required>
                            <option value="">
                                {{ $chargeType === '' ? __('Elige primero el tipo de cargo') : __('Selecciona el servicio') }}
                            </option>
                            @foreach ($servicios as $servicio)
                                <option value="{{ $servicio->service_id }}" @selected((string) $servicio->service_id === $serviceId)>
                                    {{ $servicio->description ?: __('Sin descripción') }} — {{ $money($servicio->price) }}
                                </option>
                            @endforeach
                        </select>
                        @error('serviceId') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="field-label">{{ __('Cantidad') }}</span>
                        <input type="number" step="0.0001" min="0" wire:model="quantity" value="{{ $quantity }}"
                               class="field-input mt-1.5" required>
                        @error('quantity') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="field-label">
                            {{ __('Precio') }}
                            @if ($this->priceIsFixed())
                                <span class="font-normal text-ink-faint">{{ __('(lo fija el servicio)') }}</span>
                            @endif
                        </span>
                        <input type="number" step="0.0001" min="0" wire:model="price" value="{{ $price }}"
                               @disabled($this->priceIsFixed()) class="field-input mt-1.5" required>
                        @error('price') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="flex flex-wrap justify-end gap-3">
                    <button type="button" wire:click="cancelChargeEdit" class="btn-ghost px-3 py-1.5 text-xs">{{ __('Cancelar') }}</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveCharge" class="btn-accent px-3 py-1.5 text-xs">
                        <x-spinner wire:loading wire:target="saveCharge" class="h-3.5 w-3.5" />
                        {{ __('Guardar concepto') }}
                    </button>
                </div>
            </form>
        @endif

        {{-- Tarjetas en móvil --}}
        <ul class="divide-y divide-line md:hidden">
            @forelse ($cargos as $cargo)
                <li class="space-y-2 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-ink">{{ $cargo->description ?: '—' }}</p>
                            <p class="text-xs text-ink-faint">{{ $cargo->chargeType?->charge_type_name ?: __('Sin tipo') }}</p>
                        </div>
                        <span class="shrink-0 font-semibold tabular-nums text-ink">{{ $money($cargo->total) }}</span>
                    </div>
                    <p class="text-xs text-ink-muted">
                        {{ number_format((float) $cargo->quantity, 2) }} × {{ $money($cargo->price) }}
                        · IVA {{ $money($cargo->tax) }}
                        @if ($cargo->retention > 0) · Ret. {{ $money($cargo->retention) }} @endif
                    </p>
                    @unless ($candado->locked)
                        <div class="flex gap-3 text-xs">
                            <button type="button" wire:click="editCharge({{ $cargo->charge_id }})" class="text-brand hover:underline">Editar</button>
                            <button type="button" wire:click="deleteCharge({{ $cargo->charge_id }})"
                                    wire:confirm="¿Quitar este concepto de la transacción?" class="text-ink-muted hover:text-brand">{{ __('Quitar') }}</button>
                        </div>
                    @endunless
                </li>
            @empty
                <li class="px-4 py-10 text-center text-sm text-ink-faint">{{ __('Esta transacción no tiene conceptos.') }}</li>
            @endforelse
        </ul>

        {{-- Tabla desde md --}}
        <div class="hidden overflow-x-auto md:block">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Tipo') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Descripción') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Cantidad') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Precio') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Subtotal') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('IVA') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Retención') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Total') }}</th>
                        @unless ($candado->locked)
                            <th class="px-4 py-2.5 text-right font-semibold"><span class="sr-only">{{ __('Acciones') }}</span></th>
                        @endunless
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($cargos as $cargo)
                        <tr class="transition hover:bg-raised">
                            <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ $cargo->chargeType?->charge_type_name ?: '—' }}</td>
                            <td class="max-w-[20rem] truncate px-4 py-2 text-ink" title="{{ $cargo->description }}">{{ $cargo->description ?: '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ number_format((float) $cargo->quantity, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ $money($cargo->price) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-soft">{{ $money($cargo->subtotal) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ $money($cargo->tax) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ $money($cargo->retention) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums text-ink">{{ $money($cargo->total) }}</td>
                            @unless ($candado->locked)
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    <div class="flex justify-end gap-3 text-xs">
                                        <button type="button" wire:click="editCharge({{ $cargo->charge_id }})" class="text-brand hover:underline">Editar</button>
                                        <button type="button" wire:click="deleteCharge({{ $cargo->charge_id }})"
                                                wire:confirm="¿Quitar este concepto de la transacción?" class="text-ink-muted transition hover:text-brand">{{ __('Quitar') }}</button>
                                    </div>
                                </td>
                            @endunless
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $candado->locked ? 8 : 9 }}" class="px-4 py-10 text-center text-ink-faint">
                                {{ __('Esta transacción no tiene conceptos.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Totales --}}
    <section class="card p-5 sm:p-6">
        <h3 class="text-sm font-semibold text-ink">{{ __('Totales') }}</h3>

        <dl class="mt-4 space-y-2 text-sm">
            @foreach ($desglose as [$etiqueta, $valor])
                <div class="flex justify-between gap-4">
                    <dt class="text-ink-muted">{{ $etiqueta }}</dt>
                    <dd class="tabular-nums text-ink-soft">{{ $money($valor) }}</dd>
                </div>
            @endforeach

            <div class="flex justify-between gap-4 border-t border-line pt-2 text-base font-semibold">
                <dt class="text-ink">{{ __('Total') }}</dt>
                <dd class="tabular-nums {{ (float) $fila->total_amount < 0 ? 'text-brand' : 'text-ink' }}">{{ $money($fila->total_amount) }}</dd>
            </div>

            <div class="flex justify-between gap-4">
                <dt class="text-ink-muted">{{ __('Cobrado / pagado') }}</dt>
                <dd class="tabular-nums text-ink-soft">{{ $money($fila->tran_paid_amount) }}</dd>
            </div>

            <div class="flex justify-between gap-4">
                <dt class="text-ink-muted">{{ __('Por cobrar / pagar') }}</dt>
                <dd class="tabular-nums {{ abs((float) $fila->left_to_pay) > 0.005 ? 'text-brand' : 'text-ink-soft' }}">
                    {{ $money($fila->left_to_pay) }}
                </dd>
            </div>
        </dl>
    </section>
</div>
