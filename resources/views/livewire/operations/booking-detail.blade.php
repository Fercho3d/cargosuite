@use('App\Support\PaymentStatus')

@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $fecha = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
    // El avance y la cuenta salen de los MISMOS hitos que se listan abajo: con
    // el porcentaje heredado por un lado y la lista por otro, la pantalla se
    // contradecía a sí misma.
    $esCotizacion = (int) ($booking->mode ?? 10) === \App\Models\Core\Booking::MODE_QUOTATION;
    // Las verificaciones de datos del booking, donde aplican, cuentan igual.
    $marcadas = collect($hitos)->where('marcada', true)->count() + collect($datosDelBooking)->whereNotNull('cumplida')->count();
    $totalPasos = count($hitos) + count($datosDelBooking);
    $avance = $totalPasos === 0 ? 0.0 : round($marcadas / $totalPasos * 100, 0);
@endphp

<div class="mx-auto max-w-6xl space-y-4">

    <a href="{{ route('operations.bookings') }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-ink">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        {{ __('Volver a bookings') }}
    </a>

    @if (session('error'))
        <div class="alert-danger">{{ session('error') }}</div>
    @endif

    {{-- Encabezado --}}
    <section class="card p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-faint">{{ __('Booking') }}</p>
                <h2 class="mt-0.5 truncate text-2xl font-semibold text-ink">
                    {{ trim((string) $booking->booking_number) ?: __('Sin número') }}
                </h2>
                <p class="mt-1 truncate text-sm text-ink-muted">{{ $booking->client_name ?: '—' }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($esCotizacion)
                    <span class="badge badge-neutral">{{ __('Cotización') }}</span>
                @endif
                @if ($booking->is_draft)
                    <span class="badge badge-neutral">{{ __('Borrador') }}</span>
                @endif
                @if ($booking->locked)
                    <span class="badge badge-neutral">{{ __('Cerrado') }}</span>
                @endif
                {{-- Copiar abre el alta con estos datos salvo número y buque.
                     Dar de alta es de cualquier usuario interno. --}}
                <a href="{{ route('operations.bookings.create', ['copiar' => $booking->booking_id]) }}" wire:navigate
                   title="{{ __('Abre un booking nuevo con los datos de este, salvo número y buque') }}"
                   class="btn-ghost px-3 py-1.5 text-xs">{{ __('Copiar') }}</a>
                @if (auth()->user()?->isAdmin() && ! $booking->locked)
                    <a href="{{ route('operations.bookings.edit', $booking->booking_id) }}" wire:navigate class="btn-ghost px-3 py-1.5 text-xs">
                        {{ __('Editar') }}
                    </a>
                    <a href="{{ route('operations.bookings.generate', $booking->booking_id) }}" wire:navigate
                       title="{{ __('Propone la factura y los costos a partir de los servicios contratados para esta ruta') }}"
                       class="btn-ghost px-3 py-1.5 text-xs">
                        {{ __('Generar facturación') }}
                    </a>
                @endif
                {{-- Cerrar y reabrir: solo el super administrador, como en el original. --}}
                @if (auth()->user()?->isSuperAdmin() && ! $booking->locked)
                    <button type="button" wire:click="lock"
                            wire:confirm="{{ __('Al cerrarlo ya no se podrán tocar sus contenedores ni sus documentos. ¿Continuar?') }}"
                            class="btn-ghost px-3 py-1.5 text-xs">{{ __('Cerrar booking') }}</button>
                @endif
                @if (auth()->user()?->isAdmin() && ! $booking->locked)
                    <button type="button" wire:click="delete"
                            wire:confirm="{{ __('Se borrará el booking y no se puede deshacer. ¿Continuar?') }}"
                            class="btn-ghost px-3 py-1.5 text-xs text-brand">{{ __('Borrar') }}</button>
                @endif
                @if ($booking->locked && auth()->user()?->isSuperAdmin())
                    <button type="button" wire:click="unlock"
                            wire:confirm="{{ __('Reabrir permite volver a tocar importes ya conciliados. ¿Continuar?') }}"
                            class="btn-ghost px-3 py-1.5 text-xs text-brand">{{ __('Reabrir') }}</button>
                @endif
                <a href="{{ route('operations.bookings.history', $booking->booking_id) }}" wire:navigate
                   class="btn-ghost px-3 py-1.5 text-xs">{{ __('Historial') }}</a>
                <a href="{{ route('operations.bookings.pdf', $booking->booking_id) }}" target="_blank"
                   class="btn-ghost px-3 py-1.5 text-xs">{{ __('Confirmación PDF') }}</a>
                @if (auth()->user()?->isAdmin() && ! $booking->is_draft && ! $esCotizacion)
                    <button type="button" wire:click="sendConfirmation"
                            wire:confirm="{{ __('Se le mandará al cliente la confirmación en PDF. ¿Continuar?') }}"
                            wire:loading.attr="disabled" wire:target="sendConfirmation"
                            class="btn-ghost px-3 py-1.5 text-xs">
                        <x-spinner wire:loading wire:target="sendConfirmation" class="h-3.5 w-3.5" />
                        {{ __('Enviar al cliente') }}
                    </button>
                @endif
                {{-- Para revisarla antes de mandarla de verdad: la misma confirmación,
                     al correo de quien la pide. Cualquier usuario interno, como el
                     «mail» del original. --}}
                <button type="button" wire:click="sendConfirmationToMe"
                        title="{{ __('Te manda a ti la confirmación en PDF, sin avisar al cliente') }}"
                        wire:loading.attr="disabled" wire:target="sendConfirmationToMe"
                        class="btn-ghost px-3 py-1.5 text-xs">
                    <x-spinner wire:loading wire:target="sendConfirmationToMe" class="h-3.5 w-3.5" />
                    {{ __('Enviarme una copia') }}
                </button>
                <a href="{{ route('transactions.booking', $booking->booking_id) }}" wire:navigate class="btn-ghost px-3 py-1.5 text-xs">
                    {{ __('Transacciones') }}
                </a>
            </div>
        </div>

        {{-- Borrador: se confirma aquí, ya con contenedores, y ahí sale el
             correo al cliente (salvo en cotizaciones). --}}
        @if ($booking->is_draft)
            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm">
                <p class="text-amber-900">
                    <span class="font-semibold">{{ $esCotizacion ? __('Esta cotización es un borrador.') : __('Este booking es un borrador.') }}</span>
                    {{ $esCotizacion
                        ? __('Confírmala cuando esté completa.')
                        : __('Captura sus contenedores y confírmalo para mandarle la confirmación al cliente.') }}
                </p>
                @if (auth()->user()?->isAdmin() && ! $booking->locked)
                    <button type="button" wire:click="confirm"
                            wire:confirm="{{ $esCotizacion ? __('La cotización dejará de ser borrador. ¿Continuar?') : __('Se le mandará al cliente la confirmación en PDF con los contenedores capturados. ¿Continuar?') }}"
                            wire:loading.attr="disabled" wire:target="confirm"
                            class="btn-accent !px-3 !py-1.5 text-xs">
                        <x-spinner wire:loading wire:target="confirm" class="h-3.5 w-3.5" />
                        {{ $esCotizacion ? __('Confirmar cotización') : __('Confirmar booking') }}
                    </button>
                @endif
            </div>
        @endif

        {{-- Avance --}}
        <div class="mt-5 border-t border-line pt-4">
            <div class="flex items-center justify-between gap-3 text-sm">
                <span class="text-ink-muted">{{ __('Avance de la lista de verificación') }}</span>
                <span class="font-semibold tabular-nums text-ink">
                    {{ number_format($avance, 0) }}%
                    <span class="font-normal text-ink-faint">({{ __(':marcadas de :total', ['marcadas' => $marcadas, 'total' => $totalPasos]) }})</span>
                </span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-raised">
                <div class="h-full rounded-full {{ $avance >= 90 ? 'bg-emerald-500' : ($avance >= 50 ? 'bg-amber-500' : 'bg-accent-500') }}"
                     style="width: {{ min(100, $avance) }}%"></div>
            </div>
        </div>

        <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-4 border-t border-line pt-5 text-sm sm:grid-cols-3 lg:grid-cols-4">
            @php
                // El tercer elemento es la propiedad del formulario; los campos
                // que esta instalación apagó tampoco se enseñan aquí. Sin él,
                // apagar un campo lo quitaba del alta pero seguía saliendo en
                // el detalle, vacío y sin que nadie pudiera llenarlo.
                $datos = array_filter([
                    [__('Buque'), $booking->vessel_name, 'vesselId'],
                    [__('Puerto de carga'), trim((string) $booking->port_name), 'loadingPort'],
                    [__('Puerto de descarga'), $booking->discharge_name, 'dischargePort'],
                    [__('Lugar de recolección'), $booking->pickup_name, 'pickupPlace'],
                    [__('Recolección'), $fecha($booking->pickup_date), null],
                    // El corte de instrucciones es del embarque marítimo.
                    [__('Instrucciones (SI)'), $fecha($booking->SI_date), 'vesselId'],
                    [__('Carga estimada'), $fecha($booking->loading_EDT), 'loadingDate'],
                    [__('Arribo estimado'), $fecha($booking->dicharge_ETA), 'arrivalDate'],
                    [__('Mercancía'), $booking->commodity, 'commodity'],
                    [__('Temperatura'), $booking->set_point, 'setPoint'],
                    [__('Referencia del cliente'), $booking->customer_reference, 'customerReference'],
                    [__('Creado por'), $booking->creator, null],
                ], fn (array $campo) => $campo[2] === null || \App\Support\Expediente::visible($campo[2]));

                // Y los campos propios de esta instalación, detrás de los de
                // siempre. Se leen de una vez para no consultar uno por uno.
                $valoresPropios = \App\Support\Expediente::valores($booking->booking_id);

                foreach (\App\Support\Expediente::propios() as $campo) {
                    $datos[] = [$campo->etiqueta, $valoresPropios[$campo->clave] ?? null, null];
                }
            @endphp

            @foreach ($datos as [$etiqueta, $valor, $propiedad])
                <div class="min-w-0">
                    <dt class="text-xs uppercase tracking-wide text-ink-faint">{{ $etiqueta }}</dt>
                    <dd class="mt-0.5 truncate text-ink" title="{{ $valor }}">{{ $valor ?: '—' }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    {{-- Contenedores --}}
    {{-- Gastos de carretera. Solo con flota propia: quien subcontrata el
         transporte no paga diésel — lo paga su proveedor y le llega facturado. --}}
    @if (\App\Support\Expediente::visible('unidadId'))
        @php
            $gastos = $this->gastos();
            $totalesGastos = $this->totalesGastos();
            $pesos = fn ($v) => '$'.number_format((float) $v, 2);
        @endphp

        <section class="rounded-2xl border border-line bg-panel p-4 sm:p-5">
            <header class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold text-ink">{{ __('Gastos del viaje') }}</h2>
                    <p class="mt-0.5 text-xs text-ink-faint">
                        {{ __('Combustible') }} {{ $pesos($totalesGastos['combustible']) }} ·
                        {{ __('Casetas') }} {{ $pesos($totalesGastos['caseta']) }} ·
                        {{ __('Otros') }} {{ $pesos($totalesGastos['otro']) }}
                    </p>
                </div>
                <span class="text-base font-semibold tabular-nums text-ink">{{ $pesos($totalesGastos['total']) }}</span>
            </header>

            @if (! $booking->locked && (auth()->user()?->isAdmin() ?? false))
                <div class="mb-3 grid gap-2 sm:grid-cols-6">
                    <select wire:model.live="gastoTipo" class="field-input">
                        @foreach (['combustible' => __('Combustible'), 'caseta' => __('Caseta'), 'otro' => __('Otro')] as $v => $etq)
                            <option value="{{ $v }}" @selected($gastoTipo === $v)>{{ $etq }}</option>
                        @endforeach
                    </select>
                    <input type="date" wire:model="gastoFecha" value="{{ $gastoFecha }}" class="field-input">
                    <input type="text" wire:model="gastoDescripcion" value="{{ $gastoDescripcion }}"
                           class="field-input" placeholder="{{ __('Descripción') }}">
                    @if ($gastoTipo === 'combustible')
                        <input type="number" step="0.01" wire:model="gastoLitros" value="{{ $gastoLitros }}"
                               class="field-input" placeholder="{{ __('Litros') }}">
                        <input type="number" wire:model="gastoOdometro" value="{{ $gastoOdometro }}"
                               class="field-input" placeholder="{{ __('Odómetro') }}">
                    @else
                        <input type="text" wire:model="gastoFolio" value="{{ $gastoFolio }}"
                               class="field-input sm:col-span-2" placeholder="{{ __('Folio') }}">
                    @endif
                    <div class="flex gap-2">
                        <input type="number" step="0.01" wire:model="gastoImporte" value="{{ $gastoImporte }}"
                               class="field-input" placeholder="{{ __('Importe') }}">
                        <button wire:click="saveGasto"
                                class="shrink-0 rounded-lg border border-line px-3 text-sm text-ink-soft transition hover:bg-raised">
                            {{ $gastoId ? __('Guardar') : __('Agregar') }}
                        </button>
                    </div>
                </div>
            @endif

            @if ($gastos->isEmpty())
                <p class="py-6 text-center text-sm text-ink-faint">{{ __('Sin gastos capturados.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[38rem] text-sm">
                        <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                            <tr>
                                @foreach ([__('Fecha'), __('Tipo'), __('Descripción'), __('Rendimiento'), __('Importe'), ''] as $th)
                                    <th class="px-2 py-2 text-left font-semibold">{{ $th }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($gastos as $g)
                                @php $km = \App\Support\Fleet\TripExpenses::rendimiento($g); @endphp
                                <tr>
                                    <td class="whitespace-nowrap px-2 py-1.5 text-ink-muted">
                                        {{ \Illuminate\Support\Carbon::parse($g->fecha)->format('d/m/Y') }}
                                    </td>
                                    <td class="px-2 py-1.5 text-ink-muted">
                                        {{ ['combustible' => __('Combustible'), 'caseta' => __('Caseta')][$g->tipo] ?? __('Otro') }}
                                    </td>
                                    <td class="px-2 py-1.5 text-ink">
                                        {{ $g->descripcion ?: '—' }}
                                        @if ($g->litros)
                                            <span class="text-xs text-ink-faint">
                                                · {{ number_format((float) $g->litros, 2) }} L
                                                @if ($g->precio_litro) · {{ $pesos($g->precio_litro) }}/L @endif
                                            </span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-2 py-1.5 text-ink-muted">
                                        {{ $km !== null ? number_format($km, 2).' km/L' : '—' }}
                                    </td>
                                    <td class="whitespace-nowrap px-2 py-1.5 text-right tabular-nums text-ink">{{ $pesos($g->importe) }}</td>
                                    <td class="whitespace-nowrap px-2 py-1.5 text-right">
                                        @if (! $booking->locked && (auth()->user()?->isAdmin() ?? false))
                                            <button wire:click="editGasto({{ $g->gasto_id }})" class="text-xs text-ink-faint hover:text-brand">{{ __('Editar') }}</button>
                                            <button wire:click="deleteGasto({{ $g->gasto_id }})"
                                                    wire:confirm="{{ __('¿Quitar este gasto?') }}"
                                                    class="ml-2 text-xs text-ink-faint hover:text-brand">×</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    <section class="card overflow-hidden">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
            <h3 class="text-sm font-semibold text-ink">{{ __('Contenedores') }}</h3>
            <div class="flex items-center gap-3">
                <span class="text-xs text-ink-faint">{{ $contenedores->count() }}</span>
                @if (auth()->user()?->isAdmin() && ! $booking->locked)
                    <button type="button" wire:click="addContainer" class="btn-ghost px-3 py-1.5 text-xs">{{ __('Agregar') }}</button>
                @endif
            </div>
        </header>

        @if ($editingContainer)
            <form wire:submit="saveContainer" class="space-y-4 border-b border-line bg-raised/60 p-5">
                <p class="text-sm font-medium text-ink">{{ $containerId ? __('Editar contenedor') : __('Nuevo contenedor') }}</p>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        [__('Número'), 'containerNumber', 'text'],
                        [__('Sello'), 'containerSeal', 'text'],
                        [__('Cantidad'), 'containerQuantity', 'number'],
                        [__('Mercancía'), 'containerCommodity', 'text'],
                        [__('Recolección'), 'containerPickup', 'date'],
                    ] as [$etiqueta, $propiedad, $tipo])
                        <label class="block">
                            <span class="field-label">{{ $etiqueta }}</span>
                            <input type="{{ $tipo }}" @if ($tipo === 'number') min="1" @endif
                                   wire:model="{{ $propiedad }}" value="{{ $$propiedad }}" class="field-input mt-1.5">
                            @error($propiedad) <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                        </label>
                    @endforeach

                    <label class="block">
                        <span class="field-label">{{ __('Tipo') }}</span>
                        <select wire:model="containerType" class="field-input mt-1.5">
                            <option value="">{{ __('Sin especificar') }}</option>
                            @foreach ($tiposContenedor as $id => $nombre)
                                <option value="{{ $id }}" @selected((string) $id === $containerType)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                        @error('containerType') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="flex flex-wrap justify-end gap-3">
                    <button type="button" wire:click="cancelContainerEdit" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Cancelar') }}</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveContainer" class="btn-accent !px-3 !py-1.5 text-xs">
                        <x-spinner wire:loading wire:target="saveContainer" class="h-3.5 w-3.5" />
                        {{ __('Guardar') }}
                    </button>
                </div>
            </form>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Número') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Sello') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Tipo') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Cantidad') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Mercancía') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Recolección') }}</th>
                        @if (auth()->user()?->isAdmin() && ! $booking->locked)
                            <th class="px-4 py-2.5 text-right font-semibold"><span class="sr-only">{{ __('Acciones') }}</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($contenedores as $c)
                        <tr class="transition hover:bg-raised">
                            <td class="whitespace-nowrap px-4 py-2 text-ink">{{ $c->number ?: '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ $c->seal ?: '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ $c->container_name ?: '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-muted">{{ $c->quantity ?: '—' }}</td>
                            <td class="max-w-[16rem] truncate px-4 py-2 text-ink-muted">{{ $c->comodity ?: '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ $fecha($c->pick_up_date) }}</td>
                            @if (auth()->user()?->isAdmin() && ! $booking->locked)
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    <div class="flex justify-end gap-3 text-xs">
                                        <button type="button" wire:click="editContainer({{ $c->container_ID }})" class="text-brand hover:underline">{{ __('Editar') }}</button>
                                        <button type="button" wire:click="deleteContainer({{ $c->container_ID }})"
                                                wire:confirm="{{ __('¿Quitar este contenedor del booking?') }}"
                                                class="text-ink-muted transition hover:text-brand">{{ __('Quitar') }}</button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ auth()->user()?->isAdmin() && ! $booking->locked ? 7 : 6 }}" class="px-4 py-10 text-center text-ink-faint">
                                {{ __('Este booking no tiene contenedores.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($contenedores->isNotEmpty())
                    {{-- Total de cantidades, como el pie de página del grid del viejo. --}}
                    <tfoot class="border-t border-line font-semibold">
                        <tr>
                            <td colspan="3" class="px-4 py-2 text-right text-xs uppercase tracking-wide text-ink-muted">{{ __('Total') }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink" data-total-contenedores>{{ $contenedores->sum('quantity') }}</td>
                            <td colspan="{{ auth()->user()?->isAdmin() && ! $booking->locked ? 3 : 2 }}"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </section>

    {{-- Instrucciones de embarque. Shipper, consignee y notify party son del
         conocimiento de embarque MARÍTIMO: en un viaje por carretera no
         existen, y dejarlas ahí serían ocho campos que nadie llena. --}}
    @if (\App\Support\Expediente::usa('maritimo'))
        <section class="card overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
                <div>
                    <h3 class="text-sm font-semibold text-ink">{{ __('Instrucciones de embarque') }}</h3>
                    <p class="text-xs text-ink-faint">{{ __('Cómo viene cada parte en el documento y cómo debería decir.') }}</p>
                </div>

                @if (auth()->user()?->isAdmin() && ! $booking->locked && ! $editingInstructions)
                    <button type="button" wire:click="editInstructions" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Editar') }}</button>
                @endif
            </header>

            <div class="p-5">
                @if ($editingInstructions)
                    <form wire:submit="saveInstructions" class="space-y-4">
                        @foreach ($this->instructionParts() as $parte => $etiqueta)
                            <div class="grid gap-4 sm:grid-cols-2">
                                @foreach ([['is', __('como viene')], ['should', __('como debe decir')]] as [$lado, $pie])
                                    <label class="block">
                                        <span class="field-label">
                                            {{ $etiqueta }}
                                            <span class="font-normal text-ink-faint">({{ $pie }})</span>
                                        </span>
                                        <textarea wire:model="instructions.{{ $parte }}_{{ $lado }}" rows="3"
                                                  class="field-input mt-1.5">{{ $instructions[$parte.'_'.$lado] ?? '' }}</textarea>
                                        @error('instructions.'.$parte.'_'.$lado)
                                            <span class="mt-1 block text-xs text-brand">{{ $message }}</span>
                                        @enderror
                                    </label>
                                @endforeach
                            </div>
                        @endforeach

                        <div class="flex flex-wrap justify-end gap-3 border-t border-line pt-4">
                            <button type="button" wire:click="cancelInstructions" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Cancelar') }}</button>
                            <button type="submit" wire:loading.attr="disabled" wire:target="saveInstructions" class="btn-accent !px-3 !py-1.5 text-xs">
                                <x-spinner wire:loading wire:target="saveInstructions" class="h-3.5 w-3.5" />
                                {{ __('Guardar') }}
                            </button>
                        </div>
                    </form>
                @else
                    @php $capturadas = collect($instructions)->filter()->isNotEmpty(); @endphp

                    @if (! $capturadas)
                        <p class="py-6 text-center text-sm text-ink-faint">{{ __('Este booking no tiene instrucciones capturadas.') }}</p>
                    @else
                        <dl class="space-y-4 text-sm">
                            @foreach ($this->instructionParts() as $parte => $etiqueta)
                                @continue (blank($instructions[$parte.'_is']) && blank($instructions[$parte.'_should']))
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-ink-faint">{{ $etiqueta }}</dt>
                                    <dd class="mt-1 grid gap-3 sm:grid-cols-2">
                                        @foreach ([['is', __('Como viene')], ['should', __('Como debe decir')]] as [$lado, $pie])
                                            <div class="rounded-lg border border-line px-3 py-2">
                                                <p class="text-xs text-ink-faint">{{ $pie }}</p>
                                                <p class="mt-0.5 whitespace-pre-line text-ink">{{ $instructions[$parte.'_'.$lado] ?: '—' }}</p>
                                            </div>
                                        @endforeach
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                @endif
            </div>
        </section>
    @endif

    {{-- Facturación --}}
    <section class="card overflow-hidden">
        <header class="flex items-center justify-between gap-3 border-b border-line px-5 py-3">
            <h3 class="text-sm font-semibold text-ink">{{ __('Facturación') }}</h3>
            <span class="text-xs text-ink-faint">{{ trans_choice(__('{1}:count transacción|[2,*]:count transacciones'), $transacciones->count(), ['count' => $transacciones->count()]) }}</span>
        </header>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Documento') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Aplicado a') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Fecha') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Total') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Estado') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($transacciones as $t)
                        @php $estado = PaymentStatus::for($t); @endphp
                        <tr class="transition hover:bg-raised {{ $t->cancelled ? 'opacity-50' : '' }}">
                            <td class="whitespace-nowrap px-4 py-2">
                                <a href="{{ route('transactions.show', $t->transc_id) }}" wire:navigate
                                   class="text-brand hover:underline">{{ $t->tran_number ?: __('Ver') }}</a>
                            </td>
                            <td class="max-w-[16rem] truncate px-4 py-2 text-ink-muted">{{ $t->customerName ?: ($t->vendorName ?: '—') }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ $fecha($t->tran_date) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums {{ (float) $t->total_amount < 0 ? 'text-brand' : 'text-ink' }}">
                                {{ $money($t->total_amount) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2"><span class="{{ $estado->classes() }}">{{ $estado->label() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-ink-faint">{{ __('Este booking no tiene facturación.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Documentos --}}
    @if ($documentos !== [])
        <section class="card overflow-hidden">
            <header class="flex items-center justify-between gap-3 border-b border-line px-5 py-3">
                <h3 class="text-sm font-semibold text-ink">{{ __('Documentos') }}</h3>
                <span class="text-xs text-ink-faint">
                    {{ collect($documentos)->sum(fn ($d) => count($d->files)) }} archivos
                </span>
            </header>

            <p class="border-b border-line px-5 py-2 text-xs text-ink-faint">
                {{ __('Cada cliente pide los suyos: esta lista sale de los campos configurados para') }}
                {{ $booking->client_name ?: __('este cliente') }}.
            </p>

            @error('upload') <p class="alert-danger m-5">{{ $message }}</p> @enderror

            <ul class="divide-y divide-line">
                @foreach ($documentos as $campo)
                    <li class="space-y-2 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm font-medium text-ink">{{ $campo->label }}</p>

                            @if (auth()->user()?->isAdmin() && ! $booking->locked)
                                @if ($uploadField === $campo->field_id)
                                    <span class="flex items-center gap-2">
                                        <input type="file" wire:model="upload"
                                               class="block w-full text-xs text-ink-muted file:mr-3 file:rounded-lg file:border-0 file:bg-raised file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-ink hover:file:bg-line">
                                        <span wire:loading wire:target="upload" class="inline-flex items-center gap-1.5 text-xs text-ink-muted">
                                            <x-spinner class="h-3 w-3" /> Subiendo…
                                        </span>
                                    </span>
                                @else
                                    <button type="button" wire:click="chooseField({{ $campo->field_id }})"
                                            class="btn-ghost !px-3 !py-1 text-xs">{{ __('Adjuntar') }}</button>
                                @endif
                            @endif
                        </div>

                        @if ($campo->files === [])
                            <p class="text-xs text-ink-faint">{{ __('Sin documentos.') }}</p>
                        @else
                            <ul class="flex flex-wrap gap-2">
                                @foreach ($campo->files as $archivo)
                                    <li class="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-1.5 text-xs">
                                        {{-- PDF e imágenes se abren en el navegador; lo demás se descarga. --}}
                                        @php $enLinea = \App\Support\BookingFiles::seAbreEnLinea($archivo); @endphp
                                        <a href="{{ route('operations.bookings.file', [$booking->booking_id, urlencode($archivo), 'ver' => $enLinea ? 1 : null]) }}"
                                           @if ($enLinea) target="_blank" rel="noopener" @endif
                                           class="max-w-[16rem] truncate text-brand hover:underline" title="{{ $enLinea ? __('Ver') : __('Descargar') }}: {{ $archivo }}">
                                            {{ $archivo }}
                                        </a>
                                        <a href="{{ route('operations.bookings.file', [$booking->booking_id, urlencode($archivo)]) }}"
                                           class="text-ink-faint transition hover:text-brand" title="{{ __('Descargar') }}" aria-label="{{ __('Descargar') }}">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/>
                                            </svg>
                                        </a>
                                        @if (auth()->user()?->isAdmin() && ! $booking->locked)
                                            <button type="button" wire:click="removeFile({{ $campo->field_id }}, @js($archivo))"
                                                    wire:confirm="{{ __('¿Quitar este documento del booking?') }}"
                                                    class="text-ink-faint transition hover:text-brand" aria-label="{{ __('Quitar') }}">×</button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Hitos del expediente --}}
    @if ($hitos !== [] || $datosDelBooking !== [])
        @php
            // Marcar el cumplimiento y capturar la fecha planeada son de
            // cualquier usuario interno, como en el original; desmarcar, de
            // administradores (lo revisa el componente).
            $puedeMarcar = ! $booking->locked;
            $puedeFechar = ! $booking->locked;
            $fechaHora = fn ($v) => \Illuminate\Support\Carbon::parse($v)->format('d/m/Y H:i');
            // La planeada lleva hora solo si se capturó una: a medianoche es «el día».
            $fechaPlan = function ($v) use ($fecha, $fechaHora) {
                return \Illuminate\Support\Carbon::parse($v)->format('H:i:s') === '00:00:00' ? $fecha($v) : $fechaHora($v);
            };
        @endphp

        <section id="lista-de-verificacion" class="card scroll-mt-4 p-5 sm:p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-sm font-semibold text-ink">{{ __('Lista de verificación') }}</h3>
                @if ($puedeMarcar)
                    <p class="text-xs text-ink-faint">
                        {{ __('Toca un paso para marcarlo cumplido ahora; toca la fecha planeada para cambiarla.') }}
                    </p>
                @endif
            </div>

            {{-- La modalidad (CY/CY, SD/SD…) es del embarque marítimo y vive en
                 la continuidad; se guarda en cuanto se elige. --}}
            @if ($modalidades !== [])
                <label class="mt-4 flex flex-wrap items-center gap-2 text-sm">
                    <span class="field-label text-xs">{{ __('Modalidad') }}</span>
                    <select wire:model.live="modality" @disabled($booking->locked) class="field-input !w-auto py-1 text-xs">
                        <option value="">{{ __('Sin especificar') }}</option>
                        @foreach ($modalidades as $id => $nombre)
                            <option value="{{ $id }}" @selected((string) $id === $modality)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                    @error('modality') <span class="text-xs text-brand">{{ $message }}</span> @enderror
                </label>
            @endif

            {{-- Las verificaciones de datos del booking: abren la lista en el
                 original y cuentan en el avance heredado. Misma regla que los
                 hitos: en orden para quien no es administrador. --}}
            @if ($datosDelBooking !== [])
                <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-ink-faint">{{ __('Datos del booking') }}</p>
                <ul class="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($datosDelBooking as $dato)
                        <li class="group rounded-md border-b border-line/60 px-2 py-1.5 text-sm transition
                                   {{ $puedeMarcar ? 'hover:border-transparent hover:bg-raised' : '' }}">
                            <div class="flex items-center justify-between gap-3">
                                <button type="button"
                                        @if ($puedeMarcar) wire:click="marcaDato('{{ $dato['casilla'] }}')" @else disabled @endif
                                        title="{{ $puedeMarcar ? ($dato['cumplida'] ? __('Clic para desmarcar') : __('Clic para marcar verificado')) : '' }}"
                                        class="flex min-w-0 items-center gap-2 text-left {{ $puedeMarcar ? 'cursor-pointer transition hover:text-brand' : 'cursor-default' }}">
                                    @if ($dato['cumplida'])
                                        <svg class="h-4 w-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                        </svg>
                                    @else
                                        <svg class="h-4 w-4 shrink-0 text-ink-faint transition {{ $puedeMarcar ? 'group-hover:text-brand' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <circle cx="12" cy="12" r="8"/>
                                        </svg>
                                    @endif
                                    <span class="truncate {{ $dato['cumplida'] ? 'text-ink' : 'text-ink-faint' }}">{{ $dato['etiqueta'] }}</span>
                                </button>
                                <span class="max-w-[10rem] shrink-0 truncate text-xs text-ink-faint" title="{{ $dato['valor'] }}">{{ $dato['valor'] ?: '—' }}</span>
                            </div>
                            @if ($dato['cumplida'])
                                <p class="mt-0.5 pl-6 text-xs text-ink-faint">
                                    {{ $fechaHora($dato['cumplida']) }}
                                    @if ($dato['por']) · {{ $dato['por'] }} @endif
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-ink-faint">{{ __('Continuidad') }}</p>
            @endif

            <ul class="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($hitos as $hito)
                    @php $marcable = $puedeMarcar; @endphp
                    <li class="group rounded-md border-b border-line/60 px-2 py-1.5 text-sm transition
                               {{ $marcable ? 'hover:border-transparent hover:bg-raised' : '' }}">
                        <div class="flex items-center justify-between gap-3">
                            <button type="button"
                                    @if ($marcable) wire:click="marcaHito('{{ $hito['clave'] }}')" @else disabled @endif
                                    title="{{ $marcable ? ($hito['marcada'] ? __('Clic para desmarcar') : __('Clic para marcar cumplido ahora')) : '' }}"
                                    class="flex min-w-0 items-center gap-2 text-left {{ $marcable ? 'cursor-pointer transition hover:text-brand' : 'cursor-default' }}">
                                @if ($hito['marcada'])
                                    <svg class="h-4 w-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                @else
                                    <svg class="h-4 w-4 shrink-0 text-ink-faint transition {{ $marcable ? 'group-hover:text-brand' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <circle cx="12" cy="12" r="8"/>
                                    </svg>
                                @endif
                                <span class="truncate {{ $hito['marcada'] ? 'text-ink' : 'text-ink-faint' }}">{{ __($hito['etiqueta']) }}</span>
                            </button>

                            {{-- La fecha planeada. Con casilla se rotula como tal,
                                 para no confundirla con la de cumplimiento. --}}
                            @if ($hitoEditando === $hito['clave'])
                                <span class="flex shrink-0 items-center gap-1">
                                    <input type="datetime-local" wire:model="hitoFecha" value="{{ $hitoFecha }}"
                                           wire:keydown.enter="guardaHito" wire:keydown.escape="cancelaHito"
                                           class="field-input !w-48 !py-1 text-xs">
                                    <button type="button" wire:click="guardaHito" class="text-xs text-brand hover:underline">{{ __('Guardar') }}</button>
                                    <button type="button" wire:click="cancelaHito" class="text-xs text-ink-faint hover:underline">{{ __('Cancelar') }}</button>
                                </span>
                            @else
                                <button type="button"
                                        @if ($puedeFechar) wire:click="editaHito('{{ $hito['clave'] }}')" @else disabled @endif
                                        title="{{ $puedeFechar ? __('Clic para elegir la fecha planeada') : '' }}"
                                        class="shrink-0 whitespace-nowrap text-xs text-ink-faint {{ $puedeFechar ? 'cursor-pointer transition hover:text-brand' : 'cursor-default' }}">
                                    @if ($hito['casilla'] && ($hito['fecha'] || $puedeFechar))
                                        <span class="text-ink-faint/70">{{ __('Plan') }}</span>
                                    @endif
                                    {{ $hito['fecha'] ? $fechaPlan($hito['fecha']) : ($puedeFechar ? '—' : '') }}
                                </button>
                            @endif
                        </div>

                        {{-- El cumplimiento: cuándo, quién y el «delivery time». --}}
                        @if ($hito['cumplida'])
                            <p class="mt-0.5 pl-6 text-xs text-ink-faint">
                                {{ $fechaHora($hito['cumplida']) }}
                                @if ($hito['por']) · {{ $hito['por'] }} @endif
                                @if ($hito['retraso'])
                                    · <span class="{{ $hito['retraso']['aTiempo'] ? 'text-emerald-600' : 'text-brand' }}">{{ $hito['retraso']['texto'] }}</span>
                                @endif
                            </p>
                        @endif
                    </li>
                @endforeach
            </ul>

            @error('hitoFecha')
                <p class="mt-2 text-xs text-brand">{{ $message }}</p>
            @enderror
            @error('hito')
                <p class="mt-2 text-xs text-brand">{{ $message }}</p>
            @enderror
        </section>
    @endif

    {{-- Lista de verificación del booking: las cinco fechas con hora de la
         tabla `booking`, del formulario original. Las marca el administrador. --}}
    @php $puedeMarcarBooking = (auth()->user()?->isAdmin() ?? false) && ! $booking->locked; @endphp
    <section class="card p-5 sm:p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="text-sm font-semibold text-ink">{{ __('Lista de verificación del booking') }}</h3>
            @if ($puedeMarcarBooking)
                <p class="text-xs text-ink-faint">{{ __('Toca un paso para marcarlo ahora; toca la fecha para ponerle otra.') }}</p>
            @endif
        </div>

        <ul class="mt-4 grid gap-x-6 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($listaDelBooking as $paso)
                <li class="group rounded-md border-b border-line/60 px-2 py-1.5 text-sm transition
                           {{ $puedeMarcarBooking ? 'hover:border-transparent hover:bg-raised' : '' }}">
                    <div class="flex items-center justify-between gap-3">
                        <button type="button"
                                @if ($puedeMarcarBooking) wire:click="marcaDelBooking('{{ $paso['campo'] }}')" @else disabled @endif
                                title="{{ $puedeMarcarBooking ? ($paso['fecha'] ? __('Clic para desmarcar') : __('Clic para marcar cumplido ahora')) : '' }}"
                                class="flex min-w-0 items-center gap-2 text-left {{ $puedeMarcarBooking ? 'cursor-pointer transition hover:text-brand' : 'cursor-default' }}">
                            @if ($paso['fecha'])
                                <svg class="h-4 w-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            @else
                                <svg class="h-4 w-4 shrink-0 text-ink-faint transition {{ $puedeMarcarBooking ? 'group-hover:text-brand' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <circle cx="12" cy="12" r="8"/>
                                </svg>
                            @endif
                            <span class="truncate {{ $paso['fecha'] ? 'text-ink' : 'text-ink-faint' }}">{{ $paso['etiqueta'] }}</span>
                        </button>

                        @if ($bookingCheckEditando === $paso['campo'])
                            <span class="flex shrink-0 items-center gap-1">
                                <input type="datetime-local" wire:model="bookingCheckFecha" value="{{ $bookingCheckFecha }}"
                                       wire:keydown.enter="guardaFechaDelBooking" wire:keydown.escape="cancelaFechaDelBooking"
                                       class="field-input !w-48 !py-1 text-xs">
                                <button type="button" wire:click="guardaFechaDelBooking" class="text-xs text-brand hover:underline">{{ __('Guardar') }}</button>
                                <button type="button" wire:click="cancelaFechaDelBooking" class="text-xs text-ink-faint hover:underline">{{ __('Cancelar') }}</button>
                            </span>
                        @else
                            <button type="button"
                                    @if ($puedeMarcarBooking) wire:click="editaFechaDelBooking('{{ $paso['campo'] }}')" @else disabled @endif
                                    title="{{ $puedeMarcarBooking ? __('Clic para elegir fecha y hora') : '' }}"
                                    class="shrink-0 whitespace-nowrap text-xs text-ink-faint {{ $puedeMarcarBooking ? 'cursor-pointer transition hover:text-brand' : 'cursor-default' }}">
                                {{ $paso['fecha'] ? \Illuminate\Support\Carbon::parse($paso['fecha'])->format('d/m/Y H:i') : ($puedeMarcarBooking ? '—' : '') }}
                            </button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        @error('bookingCheckFecha')
            <p class="mt-2 text-xs text-brand">{{ $message }}</p>
        @enderror
    </section>
</div>
