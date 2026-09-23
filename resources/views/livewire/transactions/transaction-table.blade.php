@use('App\Support\PaymentStatus')
@use('App\Models\Core\Transaction')

@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);

    // Cuántos filtros trae puestos: se muestra en el botón cuando el panel está
    // plegado, para que no se pierda de vista que la lista viene acotada.
    $activos = collect([$tranNumber, $bookingNumber, $appliedTo, $dates, $companyId, $accountId, $paid, $docType, $cfdiEstado])
        ->filter(fn ($v) => filled($v))
        ->count() + ($showCancelled !== '0' ? 1 : 0);
    // [clave de orden, etiqueta, alineación, clave del pie de totales]. Las
    // columnas extra siguen a los listados del original: Costos lleva PDF/XML,
    // Solicitud, Total natural y Saldo; Facturas, el pagado a TC de pago.
    $columns = [
        ['booking', __('Booking'), 'text-left', null],
        ['tran_date', __('Fecha'), 'text-left', null],
        ['tran_number', __('Número'), 'text-left', null],
        ['tran_type', __('Tipo'), 'text-left', null],
        ['applied_to', __('Aplicado a'), 'text-left', null],
        ['company', __('Compañía'), 'text-left', null],
        ['currency', 'Ccy', 'text-left', null],
        ['amount_original', __('Importe'), 'text-right', 'amount_original'],
        ['exchange_value', __('TC'), 'text-right', null],
        ['sub_0_mxn', __('Sub 0 %'), 'text-right', 'sub_0_mxn'],
        ['sub_16_mxn', __('Sub 16 %'), 'text-right', 'sub_16_mxn'],
        ['tax_16_mxn', __('IVA 16 %'), 'text-right', 'tax_16_mxn'],
        ['non_dec', 'Non Dec', 'text-right', 'non_dec'],
        ['tax_ret_mxn', __('Ret. IVA'), 'text-right', 'tax_ret_mxn'],
        ['total_amount', __('Total'), 'text-right', 'total_amount'],
        ...($screen === 'booking' ? [[null, __('Profit factura (doc)'), 'text-right', 'profit']] : []),
        // Los archivos del CFDI se consultan desde cualquier listado: antes solo
        // estaban en Costos y había que entrar al detalle para abrirlos.
        [null, 'PDF / XML', 'text-left', null],
        ...($screen === 'bill' ? [[null, __('Solicitud'), 'text-left', null]] : []),
        ...($screen === 'invoice' ? [['total_amount_paid_tc', __('Pagado (TC de pago)'), 'text-right', 'total_amount_paid_tc']] : []),
        ['tran_paid_amount', __('Pagado'), 'text-right', 'tran_paid_amount'],
        ...($screen === 'bill' ? [
            ['total_natural_amount', __('Total natural'), 'text-right', 'total_natural_amount'],
            ['left_to_pay', __('Saldo'), 'text-right', 'left_to_pay'],
            // Utilidad del booking al que pertenece el costo, no del costo: un
            // costo por sí solo no tiene utilidad.
            [null, __('Utilidad del booking'), 'text-right', null],
        ] : []),
        // Fuera de Costos, el saldo del filtro se lee en el pie de esta columna.
        [$screen === 'bill' ? null : 'left_to_pay', __('Estado'), 'text-left', $screen === 'bill' ? null : 'estado'],
        ['seal', 'CFDI', 'text-left', null],
    ];
    // Cuántas columnas de texto abren la tabla: el pie las cubre con una sola celda.
    $columnasDeTexto = 7;
@endphp

<div class="space-y-4">

    {{-- Pestañas: navegación sin recarga completa --}}
    <nav class="flex flex-wrap items-center gap-1 rounded-xl border border-line bg-panel p-1 text-sm">
        @foreach ([
            ['invoice', __('Ingresos'), route('transactions.invoice')],
            ['bill', __('Gastos'), route('transactions.bill')],
            ['all', __('Todas'), route('transactions.all')],
        ] as [$key, $label, $href])
            <a href="{{ $href }}" wire:navigate
               class="rounded-lg px-4 py-2 font-medium transition {{ $screen === $key ? 'bg-accent-500 text-white' : 'text-ink-muted hover:bg-raised hover:text-ink' }}">
                {{ $label }}
            </a>
        @endforeach

        @if ($booking)
            <a href="{{ route('operations.bookings.show', $booking->booking_id) }}" wire:navigate
               class="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-ink-muted transition hover:bg-raised hover:text-ink">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                {{ __('Volver al booking') }}
            </a>
            <span class="rounded-lg bg-raised px-4 py-2 font-medium text-ink">
                {{ __('Booking') }} {{ trim($booking->booking_number) }}
            </span>

            {{-- El alta necesita saber a qué booking pertenece; por eso solo se
                 ofrece desde esta pantalla, y no en un booking cerrado. --}}
            @if ($booking->locked)
                <span class="px-2 text-xs text-ink-faint">{{ __('Booking cerrado') }}</span>
            @else
                @foreach ([['factura', __('Nueva factura')], ['costo', __('Nuevo costo')], ['nota-credito', __('Nueva nota de crédito')]] as [$tipo, $etiqueta])
                    <a href="{{ route('transactions.create', ['booking' => $booking->booking_id, 'tipo' => $tipo]) }}"
                       wire:navigate class="btn-ghost px-3 py-1.5 text-xs">{{ $etiqueta }}</a>
                @endforeach
            @endif
        @endif

        <span class="ml-auto px-3 text-xs text-ink-faint" title="{{ __('Tiempo de la consulta que alimenta esta tabla') }}">
            @if ($screen !== 'booking')
                <span class="text-ink-muted">{{ $this->datesLabel() }}</span> ·
            @endif
            {{ number_format($rows->total()) }} registros · consulta en {{ $queryMs }} ms
        </span>
    </nav>

    {{-- Cancelar desde el listado: el SAT exige motivo, y con el 01 también el
         folio que sustituye al comprobante. --}}
    @if ($cancelling !== null)
        <div class="card space-y-3 border-brand/40 p-4">
            <p class="text-sm font-semibold text-ink">{{ __('Cancelar el CFDI ante el SAT') }}</p>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label class="block">
                    <span class="field-label text-xs">{{ __('Motivo') }}</span>
                    <select wire:model.live="cancelReason" class="field-input mt-1 py-1.5 text-sm">
                        @foreach (\App\Actions\Transactions\CancelStamp::MOTIVOS as $clave => $texto)
                            <option value="{{ $clave }}">{{ $texto }}</option>
                        @endforeach
                    </select>
                </label>

                @if ($cancelReason === '01')
                    <label class="block">
                        <span class="field-label text-xs">{{ __('Folio fiscal que la sustituye') }}</span>
                        <input type="text" wire:model="replacementUuid" class="field-input mt-1 py-1.5 text-sm font-mono"
                               placeholder="00000000-0000-0000-0000-000000000000">
                    </label>
                @endif
            </div>

            @error('cfdi')
                <p class="text-xs text-brand">{{ $message }}</p>
            @enderror

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="cancelRow" wire:loading.attr="disabled" wire:target="cancelRow"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-accent-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-accent-600">
                    <x-spinner wire:loading wire:target="cancelRow" class="h-3.5 w-3.5" />
                    {{ __('Solicitar la cancelación') }}
                </button>
                <button type="button" wire:click="cancelCancel" class="btn-ghost !py-1.5 !px-3 text-xs">{{ __('Cancelar') }}</button>
                <span class="text-xs text-ink-faint">
                    {{ __('Si la factura pasa de mil pesos, el receptor tiene 72 horas para autorizarla.') }}
                </span>
            </div>
        </div>
    @endif

    {{-- Filtros. Se pliegan en pantallas angostas para que la tabla quede a la
         vista sin tener que bajar; en pantallas anchas arrancan abiertos. --}}
    <div class="rounded-xl border border-line bg-panel"
         x-data="{ abierto: window.innerWidth >= 1024 }">

        <button type="button" x-on:click="abierto = !abierto"
                class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm font-medium text-ink-muted transition hover:text-ink">
            <svg class="h-4 w-4 shrink-0 transition-transform" :class="abierto && 'rotate-90'"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
            </svg>
            <span>{{ __('Filtros') }}</span>
            @if ($activos)
                <span class="rounded-full bg-accent-500/20 px-2 py-0.5 text-[11px] font-semibold text-brand">{{ $activos }}</span>
            @endif
            <span class="ml-auto text-xs font-normal text-ink-faint" x-text="abierto ? 'Ocultar' : 'Mostrar'"></span>
        </button>

        {{-- Los filtros llevan `value` y `@selected` además de `wire:model`: viven
             en la dirección, así que al abrir un enlace compartido la tabla ya
             viene filtrada y los campos tienen que enseñar por qué. --}}
        {{-- Los filtros ya no consultan en vivo: se capturan y se aplican con el
             botón «Filtrar» (o Enter). Así escribir no dispara una consulta por
             tecla y la pantalla se siente ligera. Por eso los campos usan
             `wire:model` diferido, no `.live`. --}}
        <form wire:submit="filtrar" x-show="abierto" x-cloak class="grid gap-3 border-t border-line p-4 sm:grid-cols-2 lg:grid-cols-4">
            <label class="block">
                <span class="field-label text-xs">{{ __('Número') }}</span>
                <input type="text" wire:model="tranNumber" value="{{ $tranNumber }}" class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('F-1234') }}">
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Booking') }}</span>
                <input type="text" wire:model="bookingNumber" value="{{ $bookingNumber }}" class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('MEX…') }}">
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Cliente o proveedor') }}</span>
                <input type="text" wire:model="appliedTo" value="{{ $appliedTo }}" class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('Nombre') }}">
            </label>

            {{-- El calendario lo maneja flatpickr (ver `dateRangePicker` en app.js);
                 va en `wire:ignore` para que el repintado de Livewire no lo pise. --}}
            <div class="block" wire:ignore x-data="dateRangePicker(@js($dates))">
                <span class="field-label flex items-center justify-between text-xs">
                    {{ __('Fechas') }}
                    {{-- El listado arranca en el año en curso por rendimiento; este
                         enlace quita el rango de un clic. --}}
                    <button type="button" wire:click="showAllYears" x-show="$wire.dates !== ''"
                            class="font-normal text-brand hover:underline">{{ __('Ver todos los años') }}</button>
                </span>
                <input type="text" x-ref="input" readonly value="{{ $dates }}"
                       class="field-input mt-1 py-1.5 text-sm cursor-pointer bg-panel"
                       placeholder="{{ __('Todos los años') }}">
            </div>

            <label class="block">
                <span class="field-label text-xs">{{ __('Compañía') }}</span>
                <select wire:model.live="companyId" class="field-input mt-1 py-1.5 text-sm">
                    <option value="">{{ __('Todas') }}</option>
                    @foreach ($companies as $id => $name)
                        <option value="{{ $id }}" @selected((string) $id === $companyId)>{{ $name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Divisa') }}</span>
                <select wire:model.live="accountId" class="field-input mt-1 py-1.5 text-sm">
                    <option value="">{{ __('Todas') }}</option>
                    @foreach ($currencies as $id => $prefix)
                        <option value="{{ $id }}" @selected((string) $id === $accountId)>{{ $prefix }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Estado de pago') }}</span>
                <select wire:model.live="paid" class="field-input mt-1 py-1.5 text-sm">
                    {{-- Ojo con el `(string)`: PHP convierte en enteros las claves
                         numéricas del arreglo, y la comparación estricta fallaría. --}}
                    @foreach (['' => __('Todas'), '0' => __('Sin pagar'), '2' => __('Parciales'), '1' => __('Pagadas')] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected((string) $valor === $paid)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>

            @if ($screen !== 'booking')
                {{-- Tipo de documento: la única forma de distinguir una nota de
                     crédito de proveedor de un costo sin mirar el signo. --}}
                <label class="block">
                    <span class="field-label text-xs">{{ __('Tipo') }}</span>
                    <select wire:model.live="docType" class="field-input mt-1 py-1.5 text-sm">
                        <option value="">{{ __('Todos') }}</option>
                        @foreach ($this->typeOptions() as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected($valor === $docType)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <label class="block">
                <span class="field-label text-xs">{{ __('Canceladas') }}</span>
                <select wire:model.live="showCancelled" class="field-input mt-1 py-1.5 text-sm">
                    @foreach (['0' => __('Solo vigentes'), '1' => __('Vigentes y canceladas'), '2' => __('Solo canceladas')] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected((string) $valor === $showCancelled)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>

            {{-- Estado ante el SAT: separa la cancelación consumada de la que
                 espera al receptor, de la que rechazaron y de la que quedó
                 marcada aquí pero sigue vigente allá. --}}
            <label class="block">
                <span class="field-label text-xs">{{ __('Estado del CFDI') }}</span>
                <select wire:model.live="cfdiEstado" class="field-input mt-1 py-1.5 text-sm">
                    <option value="">{{ __('Cualquiera') }}</option>
                    @foreach ([
                        \App\Queries\TransactionFilters::CFDI_SIN_TIMBRAR => __('Sin timbrar'),
                        \App\Queries\TransactionFilters::CFDI_TIMBRADA => __('Timbradas'),
                        \App\Models\CfdiCancelacion::VISTA_CANCELADA => __('Canceladas ante el SAT'),
                        \App\Models\CfdiCancelacion::VISTA_PROCESO => __('Cancelación en proceso'),
                        \App\Models\CfdiCancelacion::VISTA_RECHAZADA => __('Cancelación rechazada'),
                        \App\Models\CfdiCancelacion::VISTA_VIGENTE => __('Vigentes ante el SAT pese a estar marcadas'),
                    ] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected($valor === $cfdiEstado)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>

            <div class="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-4">
                <button type="submit" wire:loading.attr="disabled" wire:target="filtrar"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-accent-500 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-accent-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-500/50">
                    <x-spinner wire:loading wire:target="filtrar" class="h-3.5 w-3.5" />
                    <svg wire:loading.remove wire:target="filtrar" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 4h18l-7 8v6l-4 2v-8z"/>
                    </svg>
                    {{ __('Filtrar') }}
                </button>
                <button type="button" wire:click="clearFilters" class="btn-ghost !py-1.5 !px-3 text-xs">{{ __('Limpiar filtros') }}</button>

                <x-totals-switch />
                @if ($this->allowsSelection() && auth()->user()?->isAdmin())
                    {{-- Timbrar en lote (Facturas y booking abierto): el «Seal» del sistema viejo. --}}
                    @if ($this->allowsStamping())
                        {{-- Marcar de golpe lo que falta por timbrar: las ya timbradas
                             se quedan fuera, que es lo que uno quiere antes de timbrar. --}}
                        <button type="button" wire:click="selectUnstamped({{ Js::from(collect($rows->items())->map(fn ($r) => ['transc_id' => $r->transc_id, 'seal' => $r->seal, 'tran_type' => $r->tran_type, 'cancelled' => $r->cancelled, 'amount_original' => $r->amount_original, 'left_to_pay' => $r->left_to_pay])) }})"
                                class="btn-ghost !py-1.5 !px-3 text-xs">
                            {{ __('Marcar sin timbrar') }}
                        </button>

                        <button type="button" wire:click="stampSelected"
                                wire:confirm="{{ __('Se timbrarán ante el SAT las facturas seleccionadas. No se puede deshacer sin cancelarlas. ¿Continuar?') }}"
                                wire:loading.attr="disabled" wire:target="stampSelected"
                                @disabled($selected === [])
                                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition
                                       {{ $selected === []
                                            ? 'cursor-not-allowed border border-line text-ink-faint'
                                            : 'bg-accent-500 text-white shadow-sm hover:bg-accent-600' }}">
                            <x-spinner wire:loading wire:target="stampSelected" class="h-3.5 w-3.5" />
                            {{ __('Timbrar seleccionadas') }}
                            @if ($selected !== [])
                                <span class="rounded-full bg-white/25 px-1.5 py-0.5 tabular-nums">{{ count($selected) }}</span>
                            @endif
                        </button>
                    @endif

                    {{-- «Send Docs» del sistema viejo: PDF y XML al cliente. --}}
                    @if ($screen === 'invoice')
                        <button type="button" wire:click="sendSelected"
                                wire:confirm="{{ __('Se les mandará a los clientes el PDF y el XML de las facturas seleccionadas. ¿Continuar?') }}"
                                wire:loading.attr="disabled" wire:target="sendSelected"
                                @disabled($selected === [])
                                class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition
                                       {{ $selected === []
                                            ? 'cursor-not-allowed border-line text-ink-faint'
                                            : 'border-line text-ink hover:bg-raised' }}">
                            <x-spinner wire:loading wire:target="sendSelected" class="h-3.5 w-3.5" />
                            <svg wire:loading.remove wire:target="sendSelected" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 7l9 6 9-6M5 5h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/>
                            </svg>
                            {{ __('Enviar documentos') }}
                        </button>
                    @endif

                    {{-- Pagar: lleva a la pantalla de pago con las seleccionadas. --}}
                    <button type="button" wire:click="createPaymentRequest"
                            title="{{ __('Agrupa las seleccionadas en una solicitud de pago') }}"
                            @disabled($selected === [])
                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition
                                   {{ $selected === []
                                        ? 'cursor-not-allowed border-line text-ink-faint'
                                        : 'border-line text-ink hover:bg-raised' }}">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                             stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 7h14a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18"/>
                        </svg>
                        {{ __('Pagar') }}
                        @if ($selected !== [])
                            <span class="rounded-full bg-ink/10 px-1.5 py-0.5 tabular-nums">{{ count($selected) }}</span>
                        @endif
                    </button>
                @endif
                <a href="{{ $this->exportUrl() }}"
                   title="{{ __('Baja todo el filtro, no solo esta página.') }}"
                   class="btn-ghost !py-1.5 !px-3 text-xs">
                    <svg class="mr-1 inline h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>
                    </svg>
                    {{ __('Exportar a Excel') }}
                </a>

                {{-- Ver todas: carga el filtro completo en una sola página. --}}
                <button type="button" wire:click="verTodas" wire:loading.attr="disabled" wire:target="verTodas"
                        class="ml-auto btn-ghost !py-1.5 !px-3 text-xs">
                    <x-spinner wire:loading wire:target="verTodas" class="h-3.5 w-3.5" />
                    {{ __('Ver todas') }}
                </button>

                <label class="flex items-center gap-2 text-xs text-ink-muted">
                    {{ __('Por página') }}
                    <select wire:model.live="perPage" class="field-input !w-auto py-1 text-xs">
                        @foreach ([25, 50, 100, 200, 500, 1000] as $n)
                            <option value="{{ $n }}" @selected($n === $perPage)>{{ $n }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </form>
    </div>

    @error('selected') <p class="alert-danger">{{ $message }}</p> @enderror

    {{-- Resultado de «Enviar documentos». --}}
    @if ($sendResult !== null)
        <div class="card space-y-2 p-4 text-sm">
            <div class="flex items-center justify-between gap-3">
                <p class="font-semibold text-ink">
                    {{ __('Envío de documentos') }}:
                    <span class="text-emerald-600 dark:text-emerald-400">{{ $sendResult['sent'] }} {{ __('enviadas') }}</span>
                    @if (count($sendResult['errors']) > 0)
                        · <span class="text-brand">{{ count($sendResult['errors']) }} {{ __('sin enviar') }}</span>
                    @endif
                </p>
                <button type="button" wire:click="$set('sendResult', null)" class="text-xs text-ink-faint hover:text-ink">{{ __('Cerrar') }}</button>
            </div>

            @if (count($sendResult['errors']) > 0)
                <ul class="space-y-0.5 text-xs text-ink-muted">
                    @foreach ($sendResult['errors'] as $factura => $motivo)
                        <li><span class="font-medium text-ink">{{ $factura }}</span> — {{ $motivo }}</li>
                    @endforeach
                </ul>
            @endif

            @if (($sendResult['unsealed'] ?? []) !== [])
                <p class="text-xs text-ink-faint">
                    {{ __('Sin sello CFDI (se mandó el PDF cargado a mano): :facturas', ['facturas' => implode(', ', $sendResult['unsealed'])]) }}
                </p>
            @endif
        </div>
    @endif

    {{-- Resultado del timbrado en lote: cuántas se timbraron y cuáles no. --}}
    @if ($stampResult !== null)
        <div class="card space-y-2 p-4 text-sm">
            <div class="flex items-center justify-between gap-3">
                <p class="font-semibold text-ink">
                    {{ __('Timbrado en lote') }}:
                    <span class="text-emerald-600 dark:text-emerald-400">{{ $stampResult['done'] }} {{ __('timbradas') }}</span>
                    @if (count($stampResult['errors']) > 0)
                        · <span class="text-brand">{{ count($stampResult['errors']) }} {{ __('con error') }}</span>
                    @endif
                </p>
                <button type="button" wire:click="$set('stampResult', null)" class="text-xs text-ink-faint hover:text-ink">{{ __('Cerrar') }}</button>
            </div>

            @if (count($stampResult['errors']) > 0)
                <ul class="space-y-0.5 text-xs text-ink-muted">
                    @foreach ($stampResult['errors'] as $factura => $motivo)
                        <li><span class="font-medium text-ink">{{ $factura }}</span> — {{ $motivo }}</li>
                    @endforeach
                </ul>
            @endif

            @if (($stampResult['skipped'] ?? 0) > 0)
                <p class="text-xs text-ink-faint">
                    {{ trans_choice('Se omitió :n factura que ya estaba timbrada.|Se omitieron :n facturas que ya estaban timbradas.', $stampResult['skipped'], ['n' => $stampResult['skipped']]) }}
                </p>
            @endif

            @if ($stampResult['pending'] > 0)
                <p class="text-xs text-ink-faint">
                    {{ __('Quedaron :n sin procesar (se timbran por tandas). Vuelve a seleccionarlas y timbra otra vez.', ['n' => $stampResult['pending']]) }}
                </p>
            @endif
        </div>
    @endif

    {{-- Utilidad por booking. Solo en Facturas y bajo demanda: es la consulta
         más cara de la pantalla y no siempre se ocupa. --}}
    @if ($screen === 'invoice')
        <section class="card overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div>
                    <h3 class="text-sm font-semibold text-ink">{{ __('Utilidad por booking') }}</h3>
                    <p class="text-xs text-ink-faint">
                        {{ __('Facturas menos costos, sin IVA. Los importes son del booking completo, no solo de lo que cae en el filtro.') }}
                    </p>
                </div>

                <button type="button" wire:click="calculateProfit" wire:loading.attr="disabled" wire:target="calculateProfit"
                        class="btn-ghost px-3 py-1.5 text-xs">
                    <x-spinner wire:loading wire:target="calculateProfit" class="h-3.5 w-3.5" />
                    {{ $profit === null ? 'Calcular' : 'Recalcular' }}
                </button>
            </header>

            @if ($profit !== null)
                @if ($profit['rows'] === [])
                    <p class="border-t border-line px-5 py-8 text-center text-sm text-ink-faint">
                        {{ __('No hay bookings facturados con estos filtros.') }}
                    </p>
                @else
                    {{-- Tarjetas en móvil --}}
                    <ul class="divide-y divide-line border-t border-line md:hidden">
                        @foreach ($profit['rows'] as $fila)
                            <li class="space-y-1.5 p-4 text-sm">
                                <p class="font-medium text-ink">{{ $fila['booking'] ?: '—' }}</p>
                                @foreach ([['TC documento', 'inv_doc', 'cost_doc', 'profit_doc'], ['TC pago', 'inv_pago', 'cost_pago', 'profit_pago']] as [$titulo, $inv, $cos, $pro])
                                    <div class="flex justify-between gap-2 text-xs">
                                        <span class="text-ink-faint">{{ $titulo }}</span>
                                        <span class="tabular-nums text-ink-muted">
                                            {{ $money($fila[$inv]) }} − {{ $money($fila[$cos]) }} =
                                            <span class="font-semibold {{ $fila[$pro] < 0 ? 'text-brand' : 'text-ink' }}">{{ $money($fila[$pro]) }}</span>
                                        </span>
                                    </div>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>

                    {{-- Tabla desde md --}}
                    <div class="hidden overflow-x-auto border-t border-line md:block">
                        <table class="min-w-full text-sm">
                            <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                                <tr>
                                    <th class="px-4 py-2.5 text-left font-semibold">{{ __('Booking') }}</th>
                                    <th class="px-4 py-2.5 text-right font-semibold">{{ __('Facturado (TC doc.)') }}</th>
                                    <th class="px-4 py-2.5 text-right font-semibold">{{ __('Costo (TC doc.)') }}</th>
                                    <th class="px-4 py-2.5 text-right font-semibold">{{ __('Utilidad (TC doc.)') }}</th>
                                    <th class="px-4 py-2.5 text-right font-semibold">{{ __('Facturado (TC pago)') }}</th>
                                    <th class="px-4 py-2.5 text-right font-semibold">{{ __('Costo (TC pago)') }}</th>
                                    <th class="px-4 py-2.5 text-right font-semibold">{{ __('Utilidad (TC pago)') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($profit['rows'] as $fila)
                                    <tr class="transition hover:bg-raised">
                                        <td class="whitespace-nowrap px-4 py-2">
                                            <a href="{{ route('transactions.booking', $fila['booking_id']) }}" wire:navigate
                                               class="text-brand hover:underline">{{ $fila['booking'] ?: '—' }}</a>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ $money($fila['inv_doc']) }}</td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ $money($fila['cost_doc']) }}</td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums {{ $fila['profit_doc'] < 0 ? 'text-brand' : 'text-ink' }}">{{ $money($fila['profit_doc']) }}</td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ $money($fila['inv_pago']) }}</td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ $money($fila['cost_pago']) }}</td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums {{ $fila['profit_pago'] < 0 ? 'text-brand' : 'text-ink' }}">{{ $money($fila['profit_pago']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t border-line bg-panel text-sm font-semibold">
                                <tr>
                                    <td class="px-4 py-2.5 text-ink-muted">{{ __('Total') }}</td>
                                    @foreach (['inv_doc', 'cost_doc', 'profit_doc', 'inv_pago', 'cost_pago', 'profit_pago'] as $clave)
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums {{ str_starts_with($clave, 'profit') && $profit['totals'][$clave] < 0 ? 'text-brand' : 'text-ink' }}">
                                            {{ $money($profit['totals'][$clave]) }}
                                        </td>
                                    @endforeach
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            @endif
        </section>
    @endif

    {{-- Resultados. Tabla completa desde `md`; en móvil, tarjetas con los
         campos que de verdad se consultan de pie frente a un contenedor. --}}
    <div class="relative rounded-xl border border-line bg-panel">

        {{-- Velo de carga. Con `delay` para que un filtro rápido no parpadee.
             OJO: el modificador de display (`.flex`, `.block`…) NO se puede
             combinar con `.delay` — Livewire solo genera la regla que oculta el
             elemento para los modificadores sueltos, y el velo se quedaría
             visible tapando la tabla hasta que arrancara su JavaScript. Al estar
             posicionado en absoluto, el navegador ya lo trata como bloque, así
             que basta con centrar el aviso con `text-center`. --}}
        <div wire:loading.delay
             class="absolute inset-0 z-20 rounded-xl bg-panel/75 text-center backdrop-blur-[1px]">
            <span class="mt-14 inline-flex items-center gap-2 rounded-full border border-line bg-panel px-4 py-2 text-sm text-ink-muted shadow-lg">
                <x-spinner class="h-4 w-4 text-brand" />
                {{ __('Actualizando…') }}
            </span>
        </div>

        {{-- Tarjetas (móvil) --}}
        <ul class="divide-y divide-line md:hidden">
            @forelse ($rows as $row)
                @php $status = PaymentStatus::for($row); @endphp
                <li class="space-y-2 p-4 {{ $row->cancelled ? 'opacity-50' : '' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('transactions.booking', $row->booking) }}" wire:navigate
                               class="block truncate font-semibold text-brand hover:underline">
                                {{ trim((string) $row->booking_number) ?: '—' }}
                            </a>
                            <a href="{{ route('transactions.show', $row->transc_id) }}" wire:navigate
                               class="block truncate text-sm font-medium text-brand hover:underline">
                                {{ $row->tran_number ?: __('Sin número') }}
                            </a>
                        </div>
                        <span class="{{ $status->classes() }} shrink-0">{{ $status->label() }}</span>
                    </div>

                    <p class="truncate text-sm text-ink-muted">
                        <span class="text-xs text-ink-faint">{{ Transaction::typeLabel($row->invoice_type, $row->tran_type) }} ·</span>
                        {{ $row->customerName ?: ($row->vendorName ?: '—') }}
                    </p>

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-faint">{{ __('Fecha') }}</dt>
                            <dd class="text-ink-soft">{{ $row->tran_date ? \Illuminate\Support\Carbon::parse($row->tran_date)->format('d/m/Y') : '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-faint">{{ __('Moneda') }}</dt>
                            <dd class="text-ink-soft">{{ $row->currency ?: '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-faint">{{ __('Total') }}</dt>
                            <dd class="font-semibold tabular-nums {{ (float) $row->total_amount < 0 ? 'text-brand' : 'text-ink' }}">{{ $money($row->total_amount) }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-faint">{{ __('Pagado') }}</dt>
                            <dd class="tabular-nums text-ink-soft">{{ $money($row->tran_paid_amount) }}</dd>
                        </div>
                        @if ($screen === 'bill')
                            @php $utilidadMovil = $bookingProfits[(int) $row->booking_id]['profit_doc'] ?? null; @endphp
                            <div class="flex justify-between gap-2">
                                <dt class="text-ink-faint">{{ __('Utilidad del booking') }}</dt>
                                <dd class="tabular-nums {{ $utilidadMovil === null ? 'text-ink-faint' : ($utilidadMovil < 0 ? 'text-brand' : 'text-emerald-600') }}">
                                    {{ $utilidadMovil === null ? '—' : $money($utilidadMovil) }}
                                </dd>
                            </div>
                        @endif
                    </dl>
                </li>
            @empty
                <li class="px-4 py-12 text-center text-sm text-ink-faint">{{ __('No hay transacciones con estos filtros.') }}</li>
            @endforelse
        </ul>

        {{-- Tabla (desde md) --}}
        {{-- El <style> lo escribe `columnResizer` con los anchos guardados en
             este navegador; va con `wire:ignore` para que el morph no lo borre
             al repintar la tabla. --}}
        <div id="tabla-transacciones" x-data="columnResizer('anchos.transacciones.{{ $this->screen }}')"
             class="hidden overflow-x-auto md:block">
            <style x-ref="reglas" wire:ignore></style>
            <table class="min-w-full text-sm">
                <thead class="border-b border-line bg-panel text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        @if ($this->allowsSelection())
                            <th class="w-8 px-3 py-2.5">
                                {{-- Marcar todas las de la página: timbrar o cobrar en lote
                                     no puede empezar por cincuenta clics. --}}
                                <input type="checkbox" wire:click="toggleAll({{ Js::from(collect($rows->items())->map(fn ($r) => ['transc_id' => $r->transc_id, 'cancelled' => $r->cancelled, 'amount_original' => $r->amount_original, 'left_to_pay' => $r->left_to_pay])) }})"
                                       title="{{ __('Marcar o desmarcar todas las de esta página') }}"
                                       aria-label="{{ __('Marcar todas las de esta página') }}"
                                       class="h-4 w-4 rounded border-line bg-panel text-accent-500 focus:ring-accent-500">
                            </th>
                        @endif
                        @foreach ($columns as [$sortKey, $label, $align])
                            @php $nth = $loop->iteration + ($this->allowsSelection() ? 1 : 0); @endphp
                            <th class="relative whitespace-nowrap px-3 py-2.5 font-semibold {{ $align }}">
                                @if ($sortKey)
                                    <button type="button" wire:click="sortBy('{{ $sortKey }}')" class="inline-flex items-center gap-1 transition hover:text-ink">
                                        {{ $label }}
                                        @if ($sort === $sortKey)
                                            <span class="text-brand">{{ $direction === 'asc' ? '▲' : '▼' }}</span>
                                        @endif
                                    </button>
                                @else
                                    {{ $label }}
                                @endif

                                <span class="absolute right-0 top-0 h-full w-1.5 cursor-col-resize hover:bg-brand/50"
                                      title="{{ __('Arrastra para cambiar el ancho; doble clic para restablecerlo.') }}"
                                      @mousedown="arrastrar($event, {{ $nth }})"
                                      @dblclick="restablecer({{ $nth }})"></span>
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    @forelse ($rows as $row)
                        @php
                            $status = PaymentStatus::for($row);
                            $marcada = $this->allowsSelection() && in_array((string) $row->transc_id, $selected);
                        @endphp
                        <tr class="transition {{ $marcada ? 'row-picked' : 'hover:bg-raised' }} {{ $row->cancelled ? 'opacity-50' : '' }}">
                            @if ($this->allowsSelection())
                                @php $noSeleccionable = $this->unselectableReason($row); @endphp
                                <td class="px-3 py-2">
                                    {{-- Saldadas y canceladas no se marcan: no hay nada que
                                         pagar, y el original también apagaba la casilla. --}}
                                    <input type="checkbox" wire:model.live="selected" value="{{ $row->transc_id }}"
                                           aria-label="Seleccionar transacción {{ $row->tran_number }}"
                                           @disabled($noSeleccionable !== null) title="{{ $noSeleccionable }}"
                                           class="h-4 w-4 rounded border-line bg-panel text-accent-500 focus:ring-accent-500 disabled:cursor-not-allowed disabled:opacity-40">
                                </td>
                            @endif
                            {{-- Hay bookings que encadenan diez referencias («MXO…-TRJPT42/21-…»)
                                 y estiraban la tabla entera. Se recorta y el valor completo
                                 queda en el `title`. --}}
                            <td class="max-w-[12rem] truncate px-3 py-2" title="{{ trim((string) $row->booking_number) }}">
                                <a href="{{ route('transactions.booking', $row->booking) }}" wire:navigate
                                   class="text-brand hover:underline">{{ trim((string) $row->booking_number) ?: '—' }}</a>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">
                                {{ $row->tran_date ? \Illuminate\Support\Carbon::parse($row->tran_date)->format('d/m/Y') : '—' }}
                            </td>
                            <td class="max-w-[12rem] truncate px-3 py-2">
                                <a href="{{ route('transactions.show', $row->transc_id) }}" wire:navigate
                                   title="{{ __('Abrir').' '.($row->tran_number ?: __('la transacción')) }}"
                                   class="font-medium text-brand hover:underline">{{ $row->tran_number ?: __('Abrir') }}</a>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-xs text-ink-muted">
                                {{ Transaction::typeLabel($row->invoice_type, $row->tran_type) }}
                            </td>
                            <td class="max-w-[16rem] truncate px-3 py-2 text-ink-muted" title="{{ $row->customerName ?: $row->vendorName }}">
                                {{ $row->customerName ?: ($row->vendorName ?: '—') }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ $row->companyName ?: '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ $row->currency ?: '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-soft">{{ $money($row->amount_original) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-faint">
                                {{ $row->exchange_value === null ? '—' : number_format((float) $row->exchange_value, 4) }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">{{ $money($row->sub_0_mxn) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">{{ $money($row->sub_16_mxn) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">{{ $money($row->tax_16_mxn) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">{{ $money($row->non_dec) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">{{ $money($row->tax_ret_mxn) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums {{ (float) $row->total_amount < 0 ? 'text-brand' : 'text-ink' }}">
                                {{ $money($row->total_amount) }}
                            </td>
                            @if ($screen === 'booking')
                                @php $profitFactura = $this->invoiceProfit($row, $bookingProfit); @endphp
                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums {{ $profitFactura === null ? 'text-ink-faint' : ($profitFactura < 0 ? 'text-brand' : 'text-emerald-600') }}">
                                    {{ $profitFactura === null ? '–' : $money($profitFactura) }}
                                </td>
                            @endif
                            {{-- Los archivos del documento: cada uno abre el suyo si está cargado. --}}
                            <td class="whitespace-nowrap px-3 py-2 text-[11px]">
                                @foreach ([['pdf', $row->pdf_attach], ['xml', $row->xml_attach]] as [$tipo, $archivo])
                                    @if (filled($archivo))
                                        <a href="{{ route('transactions.file', [$row->transc_id, $tipo]) }}" target="_blank" rel="noopener" data-navigate-ignore
                                           title="{{ __('Abrir :archivo', ['archivo' => $archivo]) }}" class="badge badge-ok uppercase">{{ $tipo }}</a>
                                    @else
                                        <span class="badge badge-neutral uppercase opacity-60" title="{{ __('Sin archivo') }}">{{ $tipo }}</span>
                                    @endif
                                @endforeach
                            </td>
                            @if ($screen === 'bill')
                                {{-- «Requested» del original: la solicitud de pago que pidió este costo. --}}
                                <td class="whitespace-nowrap px-3 py-2 text-ink-muted">
                                    @if ($row->request_id && isset($requestNumbers[$row->request_id]))
                                        <a href="{{ route('payments.requests.show', $row->request_id) }}" wire:navigate
                                           class="text-brand hover:underline">{{ $requestNumbers[$row->request_id] ?: $row->request_id }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                            @endif
                            @if ($screen === 'invoice')
                                {{-- Vacío hasta que se cobra: el TC de pago es 0. --}}
                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">
                                    {{ (float) $row->total_amount_paid_tc == 0.0 ? '—' : $money($row->total_amount_paid_tc) }}
                                </td>
                            @endif
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">{{ $money($row->tran_paid_amount) }}</td>
                            @if ($screen === 'bill')
                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">{{ $money($row->total_natural_amount) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums {{ abs((float) $row->left_to_pay) > 0.005 ? 'text-brand' : 'text-ink-muted' }}">{{ $money($row->left_to_pay) }}</td>
                                @php $utilidad = $bookingProfits[(int) $row->booking_id]['profit_doc'] ?? null; @endphp
                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums {{ $utilidad === null ? 'text-ink-faint' : ($utilidad < 0 ? 'text-brand' : 'text-emerald-600') }}"
                                    @if ($utilidad !== null) title="{{ __('Facturas :inv menos costos :cost del booking, a TC del documento', ['inv' => $money($bookingProfits[(int) $row->booking_id]['inv_doc']), 'cost' => $money($bookingProfits[(int) $row->booking_id]['cost_doc'])]) }}" @endif>
                                    {{ $utilidad === null ? '—' : $money($utilidad) }}
                                </td>
                            @endif
                            <td class="whitespace-nowrap px-3 py-2">
                                {{-- La etiqueta lleva a las solicitudes que pagan el documento. --}}
                                <a href="{{ route('transactions.show', $row->transc_id) }}#solicitudes" wire:navigate
                                   title="{{ __('Ver las solicitudes de pago de esta transacción') }}"
                                   class="{{ $status->classes() }} hover:underline">{{ $status->label() }}</a>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 font-mono text-[11px] text-ink-faint" title="{{ $row->seal }}">
                                {{ $row->seal ? \Illuminate\Support\Str::limit($row->seal, 8, '…') : '—' }}

                                {{-- Cancelada es cancelada ANTE EL SAT. Una solicitud que
                                     espera al receptor no lo es, una que el receptor
                                     rechazó tampoco, y hay facturas viejas marcadas
                                     aquí que el SAT sigue viendo vigentes. --}}
                                @php
                                    $cancelacion = $cancelaciones[$row->transc_id] ?? null;
                                    $vista = $cancelacion?->estadoVisible()
                                        ?? ($row->cancelled ? \App\Models\CfdiCancelacion::VISTA_CANCELADA : null);
                                    $insignia = [
                                        \App\Models\CfdiCancelacion::VISTA_CANCELADA => ['badge-danger', __('Cancelada'), __('Cancelada ante el SAT')],
                                        \App\Models\CfdiCancelacion::VISTA_PROCESO => ['badge-warn', __('Cancelación en proceso'), __('Solicitud enviada: el receptor tiene 72 horas para autorizarla')],
                                        \App\Models\CfdiCancelacion::VISTA_RECHAZADA => ['badge-warn', __('Cancelación rechazada'), __('El receptor rechazó la cancelación: la factura sigue vigente')],
                                        \App\Models\CfdiCancelacion::VISTA_VIGENTE => ['badge-warn', __('Vigente ante el SAT'), __('Marcada como cancelada aquí, pero el SAT la sigue viendo vigente')],
                                    ][$vista] ?? null;
                                @endphp
                                @if ($insignia)
                                    <span class="badge {{ $insignia[0] }} ml-1" title="{{ $insignia[2] }}{{ $cancelacion?->verificado_at ? ' · '.__('consultado el :fecha', ['fecha' => $cancelacion->verificado_at->format('d/m/Y')]) : '' }}">{{ $insignia[1] }}</span>
                                @endif

                                {{-- Timbrar y cancelar desde el propio renglón, como los
                                     botones de la rejilla del sistema original. Para una
                                     factura suelta, marcarla y usar el lote sobra. --}}
                                @if (auth()->user()?->isAdmin() && (int) $row->tran_type === Transaction::TYPE_INVOICE)
                                    @if (blank($row->seal) && ! $row->cancelled && $this->allowsStamping())
                                        <button type="button" wire:click="stampRow({{ $row->transc_id }})"
                                                wire:confirm="{{ __('Se timbrará esta factura ante el SAT. ¿Continuar?') }}"
                                                wire:loading.attr="disabled" wire:target="stampRow({{ $row->transc_id }})"
                                                class="ml-1 text-[11px] font-semibold text-brand hover:underline">
                                            {{ __('Timbrar') }}
                                        </button>
                                    @elseif (filled($row->seal) && \App\Models\CfdiCancelacion::permiteSolicitar((bool) $row->cancelled, $cancelacion))
                                        <button type="button" wire:click="startCancel({{ $row->transc_id }})"
                                                class="ml-1 text-[11px] text-brand hover:underline">
                                            {{ $cancelacion ? __('Reintentar cancelación') : __('Cancelar') }}
                                        </button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) + ($this->allowsSelection() ? 1 : 0) }}" class="px-3 py-12 text-center text-ink-faint">
                                {{ __('No hay transacciones con estos filtros.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                @if ($totals)
                    {{-- El pie sigue a `$columns`: cada columna con clave de total
                         pinta su suma y las demás quedan vacías, así las celdas
                         no se desalinean al cambiar de pantalla. --}}
                    <tfoot class="border-t border-line bg-panel text-sm font-semibold">
                        <tr>
                            <td colspan="{{ $columnasDeTexto + ($this->allowsSelection() ? 1 : 0) }}" class="px-3 py-2.5 text-ink-muted">{{ __('Total del filtro completo') }}</td>
                            @foreach (array_slice($columns, $columnasDeTexto) as [$sortKey, $label, $align, $totalKey])
                                @if ($totalKey === 'profit')
                                    <td class="px-3 py-2.5 text-right tabular-nums {{ ($bookingProfit['profit_doc'] ?? 0) < 0 ? 'text-brand' : 'text-emerald-600' }}">
                                        {{ $money($bookingProfit['profit_doc'] ?? 0) }}
                                    </td>
                                @elseif ($totalKey === 'estado')
                                    <td class="whitespace-nowrap px-3 py-2.5 text-ink-muted">{{ __('Por cobrar/pagar') }}: {{ $money($totals['left_to_pay']) }}</td>
                                @elseif ($totalKey !== null)
                                    <td class="px-3 py-2.5 text-right tabular-nums {{ in_array($totalKey, ['amount_original', 'total_amount'], true) ? 'text-ink' : 'text-ink-soft' }}">{{ $money($totals[$totalKey]) }}</td>
                                @else
                                    <td></td>
                                @endif
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- Utilidad del booking: facturas menos costos, sin IVA, a TC del documento. --}}
    @if ($bookingProfit)
        @php $margen = $bookingProfit['inv_doc'] != 0 ? number_format($bookingProfit['profit_doc'] / $bookingProfit['inv_doc'] * 100, 1).'%' : __('s/facturas'); @endphp
        <div class="card flex flex-wrap items-center justify-end gap-x-2 gap-y-1 px-5 py-3 text-sm text-ink-muted">
            <span>{{ __('Subtotal facturas') }}: <b class="tabular-nums text-ink">{{ $money($bookingProfit['inv_doc']) }}</b></span>
            <span>−</span>
            <span>{{ __('Subtotal costos') }}: <b class="tabular-nums text-ink">{{ $money($bookingProfit['cost_doc']) }}</b></span>
            <span>=</span>
            <span>{{ __('Profit del booking') }}:
                <b class="tabular-nums {{ $bookingProfit['profit_doc'] < 0 ? 'text-brand' : 'text-emerald-600' }}">{{ $money($bookingProfit['profit_doc']) }}</b>
                <span class="text-xs">({{ __('margen') }}: {{ $margen }})</span>
            </span>
            <span class="text-xs text-ink-faint">· {{ __('sin IVA, TC documento') }}</span>
        </div>
    @endif

    {{-- Totales del filtro, en móvil: el `tfoot` de la tabla no se ve ahí. --}}
    @if ($totals)
        <div class="card space-y-1.5 p-4 text-sm md:hidden">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-faint">{{ __('Total del filtro completo') }}</p>
            <div class="flex justify-between gap-2">
                <span class="text-ink-muted">{{ __('Total') }}</span>
                <span class="font-semibold tabular-nums text-ink">{{ $money($totals['total_amount']) }}</span>
            </div>
            <div class="flex justify-between gap-2">
                <span class="text-ink-muted">{{ __('Por cobrar/pagar') }}</span>
                <span class="tabular-nums text-ink-soft">{{ $money($totals['left_to_pay']) }}</span>
            </div>
        </div>
    @endif

    <div>{{ $rows->links() }}</div>
</div>
