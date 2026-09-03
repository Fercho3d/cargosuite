@use('App\Support\PaymentStatus')

@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $fecha = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
    $esCliente = $this->isClient();
@endphp

<div class="space-y-4">

    <header>
        <h2 class="text-lg font-semibold text-ink">
            {{ $esCliente ? 'Mis facturas y embarques' : 'Mis documentos' }}
        </h2>
        <p class="text-sm text-ink-muted">{{ $this->partyName() ?: '—' }}</p>
    </header>

    {{-- Pestañas: un proveedor no tiene embarques propios --}}
    @if ($esCliente)
        <nav class="flex flex-wrap items-center gap-1 rounded-xl border border-line bg-panel p-1 text-sm">
            @foreach (['documentos' => __('Facturas'), 'embarques' => __('Embarques')] as $clave => $etiqueta)
                <button type="button" wire:click="$set('tab', '{{ $clave }}')"
                        class="rounded-lg px-4 py-2 font-medium transition
                               {{ $tab === $clave ? 'bg-accent-500 text-white' : 'text-ink-muted hover:bg-raised hover:text-ink' }}">
                    {{ $etiqueta }}
                </button>
            @endforeach
        </nav>
    @endif

    @if (session('status'))
        <p class="alert-ok">{{ session('status') }}</p>
    @endif

    @error('seleccion') <p class="alert-danger">{{ $message }}</p> @enderror

    @if ($this->allowsSelection())
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-line bg-panel px-4 py-3">
            <p class="text-sm text-ink-muted">
                {{ __('Marca los documentos que ya tengan PDF y XML para pedir su pago.') }}
            </p>
            <button type="button" wire:click="requestPayment" wire:loading.attr="disabled" wire:target="requestPayment"
                    @disabled($selected === [])
                    class="btn-accent ml-auto px-4 py-1.5 text-xs">
                <x-spinner wire:loading wire:target="requestPayment" class="h-3.5 w-3.5" />
                {{ __('Pedir pago') }}
                @if ($selected !== []) ({{ count($selected) }}) @endif
            </button>
        </div>
    @endif

    <label class="block">
        <span class="sr-only">{{ __('Buscar') }}</span>
        <input type="search" wire:model.live.debounce.400ms="search" value="{{ $search }}"
               class="field-input py-2 text-sm"
               placeholder="{{ $tab === 'embarques' ? __('Buscar por número de booking…') : __('Buscar por número de documento…') }}">
    </label>

    {{-- Sumas del filtro completo, una línea por divisa: juntar pesos con
         dólares daría un número que no significa nada. --}}
    @if ($sumas !== [])
        <section class="card overflow-hidden">
            <h3 class="border-b border-line px-5 py-2.5 text-xs font-semibold uppercase tracking-wide text-ink-muted">
                {{ $esCliente ? __('Total facturado') : __('Total de tus documentos') }}
            </h3>
            <ul class="divide-y divide-line">
                @foreach ($sumas as $suma)
                    <li class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 px-5 py-3">
                        <span class="text-sm font-semibold text-ink">
                            {{ $suma['divisa'] }}
                            <span class="ml-1 text-xs font-normal text-ink-faint">
                                {{ $suma['documentos'] }} {{ $suma['documentos'] === 1 ? __('documento') : __('documentos') }}
                            </span>
                        </span>
                        <span class="flex flex-wrap items-baseline gap-x-6 text-sm tabular-nums">
                            <span class="text-ink-soft">
                                <span class="text-xs text-ink-faint">{{ __('Total') }}</span>
                                {{ $money($suma['total']) }}
                            </span>
                            <span class="{{ round($suma['saldo'], 2) != 0.0 ? 'font-semibold text-brand' : 'text-ink-muted' }}">
                                <span class="text-xs font-normal text-ink-faint">{{ $esCliente ? __('Por pagar') : __('Por cobrar') }}</span>
                                {{ $money($suma['saldo']) }}
                            </span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="relative rounded-xl border border-line bg-panel">
        <div wire:loading.delay class="absolute inset-0 z-20 rounded-xl bg-panel/75 text-center backdrop-blur-[1px]">
            <span class="mt-14 inline-flex items-center gap-2 rounded-full border border-line bg-panel px-4 py-2 text-sm text-ink-muted shadow-lg">
                <x-spinner class="h-4 w-4 text-brand" /> Actualizando…
            </span>
        </div>

        @if ($tab === 'embarques')
            <ul class="divide-y divide-line">
                @forelse ($embarques as $embarque)
                    <li class="space-y-1.5 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="font-semibold text-ink">{{ trim((string) $embarque->booking_number) ?: __('Sin número') }}</p>
                            <span class="text-xs tabular-nums text-ink-muted">
                                {{ number_format((float) $embarque->total_completed, 0) }}% completado
                            </span>
                        </div>
                        <div class="h-1.5 overflow-hidden rounded-full bg-raised">
                            <div class="h-full rounded-full bg-emerald-500" style="width: {{ min(100, (float) $embarque->total_completed) }}%"></div>
                        </div>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 pt-1 text-xs sm:grid-cols-4">
                            @foreach ([
                                [__('Buque'), $embarque->vessel_name],
                                [__('Destino'), $embarque->discharge_name],
                                [__('Carga'), $fecha($embarque->loading_EDT)],
                                ['Arribo', $fecha($embarque->dicharge_ETA)],
                            ] as [$etiqueta, $valor])
                                <div>
                                    <dt class="text-ink-faint">{{ $etiqueta }}</dt>
                                    <dd class="truncate text-ink-soft">{{ $valor ?: '—' }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </li>
                @empty
                    <li class="px-4 py-12 text-center text-sm text-ink-faint">{{ __('No hay embarques que mostrar.') }}</li>
                @endforelse
            </ul>
        @else
            <ul class="divide-y divide-line">
                @forelse ($documentos as $documento)
                    @php $estado = PaymentStatus::for($documento); @endphp
                    @php $marcado = in_array((string) $documento->transc_id, $selected); @endphp
                    <li class="space-y-2 p-4 {{ $documento->cancelled ? 'opacity-60' : '' }} {{ $marcado ? 'row-picked' : '' }}">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="flex min-w-0 items-start gap-3">
                                @if ($this->allowsSelection())
                                    <input type="checkbox" wire:model.live="selected" value="{{ $documento->transc_id }}"
                                           aria-label="{{ __('Marcar').' '.$documento->tran_number }}"
                                           class="mt-1 h-4 w-4 shrink-0 rounded border-line bg-panel text-accent-500 focus:ring-accent-500">
                                @endif
                            <div class="min-w-0">
                                <a href="{{ route('portal.document', $documento->transc_id) }}" wire:navigate
                                   class="font-semibold text-brand hover:underline">{{ $documento->tran_number ?: __('Sin número') }}</a>
                                <p class="text-xs text-ink-muted">
                                    Booking {{ trim((string) $documento->booking_number) ?: '—' }} · {{ $fecha($documento->tran_date) }}
                                </p>
                            </div>
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($documento->cancelled)
                                    <span class="badge badge-danger">{{ __('Cancelada') }}</span>
                                @endif
                                <span class="{{ $estado->classes() }}">{{ $estado->label() }}</span>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span class="text-sm font-semibold tabular-nums text-ink">
                                {{ $money($documento->total_natural_amount) }} {{ $documento->currency }}
                            </span>

                            <div class="flex flex-wrap items-center gap-2">
                                @foreach ([['pdf', $documento->pdf_attach], ['xml', $documento->xml_attach]] as [$tipo, $archivo])
                                    @if ($archivo)
                                        <a href="{{ route('portal.file', [$documento->transc_id, $tipo]) }}" target="_blank"
                                           class="btn-ghost !px-3 !py-1 text-xs uppercase">{{ $tipo }}</a>
                                    @endif
                                @endforeach

                                {{-- El acceso a subir la factura va explícito, como la
                                     acción de la rejilla del portal viejo: dejarlo solo en
                                     el número no se ve. --}}
                                @if (! $esCliente && ! $documento->cancelled)
                                    <a href="{{ route('portal.document', $documento->transc_id) }}" wire:navigate
                                       class="{{ (blank($documento->pdf_attach) || blank($documento->xml_attach)) ? 'btn-accent' : 'btn-ghost' }} !px-3 !py-1 text-xs">
                                        {{ (blank($documento->pdf_attach) || blank($documento->xml_attach))
                                            ? __('Subir factura')
                                            : __('Ver / cambiar') }}
                                    </a>
                                @endif

                                {{-- Se marca una sola vez, como en el portal viejo. --}}
                                @if ($esCliente)
                                    @if ($procesadas[$documento->transc_id] ?? false)
                                        <span class="badge badge-ok">{{ __('Recibida') }}</span>
                                    @elseif (! $documento->cancelled)
                                        <button type="button" wire:click="markProcessed({{ $documento->transc_id }})"
                                                class="btn-ghost !px-3 !py-1 text-xs">{{ __('Dar por recibida') }}</button>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-12 text-center text-sm text-ink-faint">{{ __('No hay documentos que mostrar.') }}</li>
                @endforelse
            </ul>
        @endif
    </div>

    @if ($tab !== 'embarques' && $documentos !== null)
        <div>{{ $documentos->links() }}</div>
    @endif
</div>
