@use('App\Support\PaymentStatus')
@use('App\Models\Core\Transaction')

@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    // Las tasas se guardan como fracción (0.16); sin tipo de cargo cuentan como 0, igual que el IFNULL de Yii2.
    $percent = fn ($v) => rtrim(rtrim(number_format((float) $v * 100, 2), '0'), '.').' %';
    $estado = PaymentStatus::for($fila);
    $esFactura = (int) $fila->tran_type === Transaction::TYPE_INVOICE;
    $contraparte = $esFactura ? $fila->customerName : $fila->vendorName;

    // El desglose del pie se toma de las mismas columnas que alimentan la tabla,
    // para que los importes cuadren al centavo con el listado.
    $desglose = [
        [__('Subtotal 0 %'), $fila->sub_0_mxn],
        [__('Subtotal 16 %'), $fila->sub_16_mxn],
        [__('IVA 16 %'), $fila->tax_16_mxn],
        [__('Retención IVA'), $fila->tax_ret_mxn],
    ];
@endphp

<div class="mx-auto max-w-5xl space-y-4">

    {{-- Regreso a las transacciones de su booking, que es de donde se llega --}}
    <a href="{{ route('transactions.booking', $fila->booking) }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-ink">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        {{ __('Ingresos y egresos del booking') }} {{ trim((string) $fila->booking_number) }}
    </a>

    {{-- Encabezado --}}
    <section class="card p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-faint">
                    {{ Transaction::typeLabel($fila->invoice_type, $fila->tran_type) }}
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
                {{-- Basta el PDF, como en el original: las facturas históricas con
                     PDF cargado a mano y sin sello también se reenvían. --}}
                @if ($esFactura && filled($transaccion->pdf_attach) && auth()->user()?->isAdmin())
                    <button type="button" wire:click="resend" wire:loading.attr="disabled" wire:target="resend"
                            wire:confirm="{{ filled($transaccion->seal)
                                ? __('Se le volverá a mandar al cliente la factura con su PDF y su XML. ¿Continuar?')
                                : __('Esta factura no tiene sello CFDI: se mandará el PDF cargado a mano. ¿Continuar?') }}"
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
                {{-- La insignia dice lo que ve el SAT, no solo la marca local: hay
                     facturas marcadas como canceladas que siguen vigentes allá. --}}
                @php
                    $vista = $satEstado === 'Vigente' && ! $cancelacion?->estaPendiente()
                        ? \App\Models\CfdiCancelacion::VISTA_VIGENTE
                        : ($cancelacion?->estadoVisible() ?? ($fila->cancelled ? \App\Models\CfdiCancelacion::VISTA_CANCELADA : null));
                    [$claseVista, $textoVista] = match ($vista) {
                        \App\Models\CfdiCancelacion::VISTA_CANCELADA => ['badge-danger', __('Cancelada')],
                        \App\Models\CfdiCancelacion::VISTA_PROCESO => ['badge-warn', __('Cancelación en proceso')],
                        \App\Models\CfdiCancelacion::VISTA_RECHAZADA => ['badge-warn', __('Cancelación rechazada')],
                        \App\Models\CfdiCancelacion::VISTA_VIGENTE => ['badge-warn', __('Vigente ante el SAT')],
                        default => [null, null],
                    };
                @endphp
                @if ($claseVista && ($fila->cancelled || $cancelacion))
                    <span class="badge {{ $claseVista }}">{{ $textoVista }}</span>
                @endif
                @if (filled($fila->seal))
                    <span class="badge badge-ok">{{ __('Timbrada') }}</span>
                @endif
            </div>
        </div>

        @error('cfdi')
            <p class="alert-danger mt-4">{{ $message }}</p>
        @enderror

        @if ($avisoEmisor = $this->emisorWarning())
            <p class="alert-danger mt-4">{{ $avisoEmisor }}</p>
        @endif

        {{-- Estatus ante el SAT de toda factura timbrada. Si está marcada como
             cancelada o hay una solicitud, se consulta solo al abrir: es justo
             el caso en que la marca local y el SAT pueden no coincidir. --}}
        @if (filled($fila->seal) || $cancelacion)
            <div class="mt-4 space-y-2 rounded-xl border border-line bg-raised/60 p-4"
                 @if ($satEstado === null && ($fila->cancelled || $cancelacion)) wire:init="refreshSatStatus" @endif>
                <p class="text-sm font-medium text-ink">{{ $cancelacion ? __('Cancelación solicitada') : __('Estatus ante el SAT') }}</p>
                @if ($cancelacion)
                    <p class="text-sm text-ink-muted">
                        {{ __($cancelacion->mensaje) }}
                        @if (filled($cancelacion->codigo))
                            <span class="text-xs text-ink-faint">({{ $cancelacion->codigo }})</span>
                        @endif
                    </p>

                    <p class="text-xs text-ink-faint">
                        {{ __('Solicitada el :fecha', ['fecha' => $cancelacion->solicitado_at?->format('d/m/Y H:i') ?: '—']) }}
                        @if ($cancelacion->verificado_at)
                            · {{ __('Última consulta al SAT: :fecha', ['fecha' => $cancelacion->verificado_at->format('d/m/Y H:i')]) }}
                            @if (filled($cancelacion->sat_estado))
                                · <x-sat-badge :estado="$cancelacion->sat_estado" />
                            @endif
                            @if (filled($cancelacion->sat_estatus))
                                <x-sat-badge :estado="$cancelacion->sat_estatus" />
                            @endif
                        @else
                            · {{ __('Todavía sin consultar al SAT.') }}
                        @endif
                    </p>
                @endif

                {{-- Con solicitud, la línea de la última consulta ya trae este resultado. --}}
                @if ($satEstado !== null && ! $cancelacion)
                    <p class="flex flex-wrap items-center gap-1.5 text-sm text-ink">
                        {{ __('El SAT dice: ') }}
                        <x-sat-badge :estado="$satEstado" />
                        @if ($satEstatus)
                            <x-sat-badge :estado="$satEstatus" />
                        @endif
                    </p>
                @elseif ($satEstado === null && $satNotice)
                    <p class="text-sm text-ink">{{ $satNotice }}</p>
                @elseif (! $cancelacion)
                    <p class="text-xs text-ink-faint">
                        <x-spinner wire:loading wire:target="refreshSatStatus" class="h-3.5 w-3.5" />
                        {{ __('Todavía sin consultar al SAT.') }}
                    </p>
                @endif

                {{-- Mientras el receptor no conteste, quien factura suele tener que
                     explicarle cómo aceptarla: el texto va listo para copiar. --}}
                @if ($cancelacion?->estadoVisible() === \App\Models\CfdiCancelacion::VISTA_PROCESO)
                    @php
                        $instrucciones = __('Les solicitamos la cancelación de la factura con folio fiscal :uuid. Para aceptarla:', ['uuid' => $cancelacion->uuid])."\n"
                            .'1. '.__('Entrar a sat.gob.mx, sección Factura electrónica, opción de cancelación de facturas («Consultar, cancelar y recuperar»).')."\n"
                            .'2. '.__('Iniciar sesión con RFC y contraseña o con e.firma de su empresa.')."\n"
                            .'3. '.__('Buscar las solicitudes de cancelación pendientes (aceptación o rechazo, como receptor).')."\n"
                            .'4. '.__('Seleccionar el folio y elegir Aceptar.')."\n"
                            .__('Si no contestan en 72 horas, el SAT la cancela automáticamente.');
                    @endphp
                    @php
                        $vence = $cancelacion->solicitado_at?->copy()->addHours(\App\Models\CfdiCancelacion::PLAZO_HORAS);
                        // Consultado y el SAT la ve vigente sin solicitud: se quedó en el PAC.
                        $noLlegoAlSat = $cancelacion->sat_estado === 'Vigente' && blank($cancelacion->sat_estatus);
                    @endphp
                    @if ($noLlegoAlSat)
                        <p class="text-xs text-brand">{{ __('El SAT todavía no tiene registrada esta solicitud: sigue en el PAC. Normalmente llega en minutos; mientras no llegue, el receptor no puede aceptarla ni corre el plazo de 72 horas. Vuelva a consultar más tarde y, si sigue sin aparecer, cancélela desde el portal del SAT con la e.firma del emisor o reporte el folio al PAC.') }}</p>
                    @elseif ($vence?->isFuture())
                        <p class="text-xs text-ink">
                            {{ __('Espere 72 horas: si el receptor no contesta, el SAT la cancela solo alrededor del :fecha (faltan :horas horas).', ['fecha' => $vence->format('d/m/Y H:i'), 'horas' => (int) ceil(now()->diffInHours($vence))]) }}
                        </p>
                    @endif

                    {{-- Plegado por omisión; `wire:ignore.self` para que al volver a
                         consultar al SAT no se cierre solo. --}}
                    @unless ($noLlegoAlSat)
                    <details wire:ignore.self x-data="{ copiado: false }" class="group rounded-lg border border-line bg-panel">
                        <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2 text-sm font-medium text-ink">
                            <svg class="h-4 w-4 shrink-0 text-ink-faint transition group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                            {{ __('Cómo acepta la cancelación :cliente', ['cliente' => $contraparte]) }}
                        </summary>
                        <div class="space-y-2 border-t border-line p-3">
                            <pre x-ref="texto" class="whitespace-pre-wrap font-sans text-xs text-ink-muted">{{ $instrucciones }}</pre>
                            <button type="button" class="btn-ghost px-3 py-1.5 text-xs"
                                    x-on:click="navigator.clipboard.writeText($refs.texto.innerText); copiado = true; setTimeout(() => copiado = false, 2000)">
                                <span x-text="copiado ? @js(__('Copiado')) : @js(__('Copiar instrucciones para el cliente'))">{{ __('Copiar instrucciones para el cliente') }}</span>
                            </button>
                        </div>
                    </details>
                    @endunless
                @endif

                <button type="button" wire:click="refreshSatStatus" wire:loading.attr="disabled" wire:target="refreshSatStatus"
                        class="btn-ghost px-3 py-1.5 text-xs">
                    <x-spinner wire:loading wire:target="refreshSatStatus" class="h-3.5 w-3.5" />
                    {{ __('Consultar estado en el SAT') }}
                </button>
            </div>
        @endif

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
                    <button type="button" wire:click="$set('cancelling', false)" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Cerrar') }}</button>
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
                        @php $url = route('transactions.file', [$fila->transc_id, $tipo]); @endphp
                        <div class="inline-flex max-w-full items-center gap-2 rounded-lg border border-line py-1 pl-3 pr-1 text-xs text-ink-muted">
                            <span class="font-semibold uppercase text-ink-faint">{{ $tipo }}</span>
                            <span class="max-w-[12rem] truncate" title="{{ $archivo }}">{{ $archivo }}</span>
                            <a href="{{ $url }}" target="_blank" rel="noopener" data-navigate-ignore
                               class="rounded-md px-2 py-1 font-medium text-ink transition hover:bg-raised">{{ __('Ver') }}</a>
                            <a href="{{ $url }}?descargar=1" download data-navigate-ignore
                               class="rounded-md px-2 py-1 font-medium text-ink transition hover:bg-raised">{{ __('Descargar') }}</a>
                        </div>
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
                    {{ trans_choice(':n línea|:n líneas', $cargos->count(), ['n' => $cargos->count()]) }}
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
                        <input type="text" inputmode="decimal" x-data="campoImporte" wire:model="quantity" value="{{ $quantity }}"
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
                        <input type="text" inputmode="decimal" x-data="campoImporte" wire:model="price" value="{{ $price }}"
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
                        · {{ __('IVA') }} {{ $money($cargo->tax) }}
                        @if ($cargo->retention > 0) · {{ __('Ret.') }} {{ $money($cargo->retention) }} @endif
                    </p>
                    @unless ($candado->locked)
                        {{-- Confirmación en la misma fila y no con confirm(): si el navegador
                             tiene bloqueados los diálogos, confirm() contesta «no» sin mostrarse. --}}
                        <div x-data="{ seguro: false }" class="flex gap-3 text-xs">
                            <template x-if="! seguro">
                                <div class="flex gap-3">
                                    <button type="button" wire:click="editCharge({{ $cargo->charge_id }})" class="text-brand hover:underline">{{ __('Editar') }}</button>
                                    <button type="button" x-on:click="seguro = true" class="text-ink-muted hover:text-brand">{{ __('Quitar') }}</button>
                                </div>
                            </template>
                            <template x-if="seguro">
                                <div class="flex gap-3">
                                    <span class="text-ink-muted">{{ __('¿Quitar?') }}</span>
                                    <button type="button" wire:click="deleteCharge({{ $cargo->charge_id }})" class="font-semibold text-brand hover:underline">{{ __('Sí') }}</button>
                                    <button type="button" x-on:click="seguro = false" class="text-ink-muted hover:text-ink">{{ __('No') }}</button>
                                </div>
                            </template>
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
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Prepagado') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Descripción') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Cantidad') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Unidad') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Precio') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Subtotal') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Tasa de IVA') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('IVA') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Tasa de retención') }}</th>
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
                            {{-- En Yii2 `prepaid` nulo se leía como «No» (IFNULL en ChargeSearch). --}}
                            <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ (int) $cargo->prepaid === 1 ? __('Sí') : __('No') }}</td>
                            <td class="max-w-[20rem] truncate px-4 py-2 text-ink" title="{{ $cargo->description }}">{{ $cargo->description ?: '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ number_format((float) $cargo->quantity, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ $cargo->unit === null ? '—' : number_format($cargo->unit, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ $money($cargo->price) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-soft">{{ $money($cargo->subtotal) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ $percent($cargo->chargeType?->tax_rate) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ $money($cargo->tax) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ $percent($cargo->chargeType?->tax_retention) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ $money($cargo->retention) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums text-ink">{{ $money($cargo->total) }}</td>
                            @unless ($candado->locked)
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    {{-- Confirmación en la misma fila y no con confirm(): si el navegador
                                         tiene bloqueados los diálogos, confirm() contesta «no» sin mostrarse. --}}
                                    <div x-data="{ seguro: false }" class="flex justify-end gap-3 text-xs">
                                        <template x-if="! seguro">
                                            <div class="flex gap-3">
                                                <button type="button" wire:click="editCharge({{ $cargo->charge_id }})" class="text-brand hover:underline">{{ __('Editar') }}</button>
                                                <button type="button" x-on:click="seguro = true" class="text-ink-muted transition hover:text-brand">{{ __('Quitar') }}</button>
                                            </div>
                                        </template>
                                        <template x-if="seguro">
                                            <div class="flex gap-3">
                                                <span class="text-ink-muted">{{ __('¿Quitar?') }}</span>
                                                <button type="button" wire:click="deleteCharge({{ $cargo->charge_id }})" class="font-semibold text-brand hover:underline">{{ __('Sí') }}</button>
                                                <button type="button" x-on:click="seguro = false" class="text-ink-muted hover:text-ink">{{ __('No') }}</button>
                                            </div>
                                        </template>
                                    </div>
                                </td>
                            @endunless
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $candado->locked ? 12 : 13 }}" class="px-4 py-10 text-center text-ink-faint">
                                {{ __('Esta transacción no tiene conceptos.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Solicitudes de pago que cobran o pagan este documento. La etiqueta de
         estado del listado apunta aquí (#solicitudes). --}}
    <section id="solicitudes" class="card overflow-hidden">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
            <h3 class="text-sm font-semibold text-ink">{{ __('Solicitudes de pago') }}</h3>
            <span class="text-xs text-ink-faint">
                {{ trans_choice(':n solicitud|:n solicitudes', $solicitudes->count(), ['n' => $solicitudes->count()]) }}
            </span>
        </header>

        @if ($solicitudes->isEmpty())
            <p class="px-5 py-6 text-center text-sm text-ink-faint">{{ __('Ninguna solicitud de pago incluye esta transacción.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                        <tr>
                            <th class="px-4 py-2.5 text-left font-semibold">{{ __('Número') }}</th>
                            <th class="px-4 py-2.5 text-left font-semibold">{{ __('Fecha') }}</th>
                            <th class="px-4 py-2.5 text-left font-semibold">{{ __('Banco') }}</th>
                            <th class="px-4 py-2.5 text-right font-semibold">{{ __('Aplicado') }}</th>
                            <th class="px-4 py-2.5 text-left font-semibold">{{ __('Estado') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($solicitudes as $solicitud)
                            <tr class="transition hover:bg-raised">
                                <td class="whitespace-nowrap px-4 py-2">
                                    <a href="{{ route('payments.requests.show', $solicitud->request_id) }}" wire:navigate
                                       class="font-medium text-brand hover:underline">{{ $solicitud->number ?: $solicitud->request_id }}</a>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-ink-muted">
                                    {{ $solicitud->date ? \Illuminate\Support\Carbon::parse($solicitud->date)->format('d/m/Y') : '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ $solicitud->bank_name ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink">
                                    {{ $money($solicitud->amount_original_paid) }} <span class="text-xs text-ink-faint">{{ $solicitud->prefix }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2">
                                    <span class="{{ $solicitud->paid ? 'badge badge-ok' : 'badge badge-warn' }}">
                                        {{ $solicitud->paid ? __('Pagada') : __('Pendiente') }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
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
