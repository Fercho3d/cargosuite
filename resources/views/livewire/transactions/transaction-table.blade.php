@use('App\Support\PaymentStatus')

@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);

    // Cuántos filtros trae puestos: se muestra en el botón cuando el panel está
    // plegado, para que no se pierda de vista que la lista viene acotada.
    $activos = collect([$tranNumber, $bookingNumber, $appliedTo, $dates, $companyId, $accountId, $paid])
        ->filter(fn ($v) => filled($v))
        ->count() + ($showCancelled !== '0' ? 1 : 0);
    $columns = [
        ['booking', 'Booking', 'text-left'],
        ['tran_date', __('Fecha'), 'text-left'],
        ['tran_number', __('Número'), 'text-left'],
        ['applied_to', __('Aplicado a'), 'text-left'],
        ['company', __('Compañía'), 'text-left'],
        ['currency', 'Ccy', 'text-left'],
        ['amount_original', __('Importe'), 'text-right'],
        ['exchange_value', 'TC', 'text-right'],
        ['sub_0_mxn', 'Sub 0 %', 'text-right'],
        ['sub_16_mxn', 'Sub 16 %', 'text-right'],
        ['tax_16_mxn', 'IVA 16 %', 'text-right'],
        ['tax_ret_mxn', 'Ret. IVA', 'text-right'],
        ['total_amount', __('Total'), 'text-right'],
        ['tran_paid_amount', __('Pagado'), 'text-right'],
        ['left_to_pay', __('Estado'), 'text-left'],
        ['seal', 'CFDI', 'text-left'],
    ];
@endphp

<div class="space-y-4">

    {{-- Pestañas: navegación sin recarga completa --}}
    <nav class="flex flex-wrap items-center gap-1 rounded-xl border border-line bg-panel p-1 text-sm">
        @foreach ([
            ['invoice', __('Facturas'), route('transactions.invoice')],
            ['bill', __('Costos'), route('transactions.bill')],
            ['all', __('Todas'), route('transactions.all')],
        ] as [$key, $label, $href])
            <a href="{{ $href }}" wire:navigate
               class="rounded-lg px-4 py-2 font-medium transition {{ $screen === $key ? 'bg-accent-500 text-white' : 'text-ink-muted hover:bg-raised hover:text-ink' }}">
                {{ $label }}
            </a>
        @endforeach

        @if ($booking)
            <span class="rounded-lg bg-raised px-4 py-2 font-medium text-ink">
                Booking {{ trim($booking->booking_number) }}
            </span>

            {{-- El alta necesita saber a qué booking pertenece; por eso solo se
                 ofrece desde esta pantalla. --}}
            @foreach ([['factura', __('Nueva factura')], ['costo', __('Nuevo costo')]] as [$tipo, $etiqueta])
                <a href="{{ route('transactions.create', ['booking' => $booking->booking_id, 'tipo' => $tipo]) }}"
                   wire:navigate class="btn-ghost px-3 py-1.5 text-xs">{{ $etiqueta }}</a>
            @endforeach
        @endif

        <span class="ml-auto px-3 text-xs text-ink-faint" title="{{ __('Tiempo de la consulta que alimenta esta tabla') }}">
            {{ number_format($rows->total()) }} registros · consulta en {{ $queryMs }} ms
        </span>
    </nav>

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
        <div x-show="abierto" x-cloak class="grid gap-3 border-t border-line p-4 sm:grid-cols-2 lg:grid-cols-4">
            <label class="block">
                <span class="field-label text-xs">{{ __('Número') }}</span>
                <input type="text" wire:model.live.debounce.400ms="tranNumber" value="{{ $tranNumber }}" class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('F-1234') }}">
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Booking') }}</span>
                <input type="text" wire:model.live.debounce.400ms="bookingNumber" value="{{ $bookingNumber }}" class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('MEX…') }}">
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Cliente o proveedor') }}</span>
                <input type="text" wire:model.live.debounce.400ms="appliedTo" value="{{ $appliedTo }}" class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('Nombre') }}">
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Fechas') }} <span class="text-ink-faint">{{ __('(dd/mm/aaaa - dd/mm/aaaa)') }}</span></span>
                <input type="text" wire:model.live.debounce.600ms="dates" value="{{ $dates }}" class="field-input mt-1 py-1.5 text-sm" placeholder="01/01/2025 - 31/12/2025">
            </label>

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

            <label class="block">
                <span class="field-label text-xs">{{ __('Canceladas') }}</span>
                <select wire:model.live="showCancelled" class="field-input mt-1 py-1.5 text-sm">
                    @foreach (['0' => __('Solo vigentes'), '1' => __('Vigentes y canceladas'), '2' => __('Solo canceladas')] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected((string) $valor === $showCancelled)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <button type="button" wire:click="clearFilters" class="btn-ghost !py-1.5 !px-3 text-xs">{{ __('Limpiar filtros') }}</button>
                <button type="button" wire:click="calculateTotals" class="btn-ghost !py-1.5 !px-3 text-xs">
                    {{ __('Sumar todo el filtro') }}
                </button>
                @if ($this->allowsSelection() && auth()->user()?->isAdmin())
                    <button type="button" wire:click="createPaymentRequest"
                            @disabled($selected === [])
                            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition
                                   {{ $selected === []
                                        ? 'cursor-not-allowed border border-line text-ink-faint'
                                        : 'bg-accent-500 text-white shadow-sm hover:bg-accent-600' }}">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                             stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v8m-4-4h8M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/>
                        </svg>
                        {{ __('Agrupar en solicitud de pago') }}
                        @if ($selected !== [])
                            <span class="rounded-full bg-white/25 px-1.5 py-0.5 tabular-nums">{{ count($selected) }}</span>
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

                <label class="ml-auto flex items-center gap-2 text-xs text-ink-muted">
                    {{ __('Por página') }}
                    <select wire:model.live="perPage" class="field-input !w-auto py-1 text-xs">
                        @foreach ([25, 50, 100, 200] as $n)
                            <option value="{{ $n }}" @selected($n === $perPage)>{{ $n }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>
    </div>

    @error('selected') <p class="alert-danger">{{ $message }}</p> @enderror

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

                    <p class="truncate text-sm text-ink-muted">{{ $row->customerName ?: ($row->vendorName ?: '—') }}</p>

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
                            <th class="w-8 px-3 py-2.5"><span class="sr-only">{{ __('Selección') }}</span></th>
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
                                <td class="px-3 py-2">
                                    <input type="checkbox" wire:model.live="selected" value="{{ $row->transc_id }}"
                                           aria-label="Seleccionar transacción {{ $row->tran_number }}"
                                           class="h-4 w-4 rounded border-line bg-panel text-accent-500 focus:ring-accent-500">
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
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">{{ $money($row->tax_ret_mxn) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums {{ (float) $row->total_amount < 0 ? 'text-brand' : 'text-ink' }}">
                                {{ $money($row->total_amount) }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-ink-muted">{{ $money($row->tran_paid_amount) }}</td>
                            <td class="whitespace-nowrap px-3 py-2">
                                <span class="{{ $status->classes() }}">{{ $status->label() }}</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 font-mono text-[11px] text-ink-faint" title="{{ $row->seal }}">
                                {{ $row->seal ? \Illuminate\Support\Str::limit($row->seal, 8, '…') : '—' }}
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
                    <tfoot class="border-t border-line bg-panel text-sm font-semibold">
                        <tr>
                            <td colspan="{{ 6 + ($this->allowsSelection() ? 1 : 0) }}" class="px-3 py-2.5 text-ink-muted">{{ __('Total del filtro completo') }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink">{{ $money($totals['amount_original']) }}</td>
                            <td></td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink-soft">{{ $money($totals['sub_0_mxn']) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink-soft">{{ $money($totals['sub_16_mxn']) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink-soft">{{ $money($totals['tax_16_mxn']) }}</td>
                            <td></td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink">{{ $money($totals['total_amount']) }}</td>
                            <td colspan="3" class="px-3 py-2.5 text-right text-ink-muted">
                                Por cobrar/pagar: {{ $money($totals['left_to_pay']) }}
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

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
