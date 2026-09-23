@php
    $verBuque = \App\Support\Expediente::visible('vesselId');
    $verTipo = \App\Support\Expediente::visible('bookingType');
    $tipos = \App\Models\Core\Booking::typeLabels();
@endphp
@php
    $fecha = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';

    // El color del avance: lo que va tarde tiene que saltar a la vista.
    $tonoAvance = fn (float $pct) => match (true) {
        $pct >= 90 => 'bg-emerald-500',
        $pct >= 50 => 'bg-amber-500',
        default => 'bg-accent-500',
    };
@endphp

<div class="space-y-4">

    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-ink">
                {{ $mode === '9' ? __('Cotizaciones') : __('Bookings') }}
                @if ($drafts === '1')
                    <span class="badge badge-neutral align-middle">{{ __('Borradores') }}</span>
                @endif
            </h2>
            <p class="text-sm text-ink-muted">
                {{ __('Embarques y el avance de su lista de verificación.') }}
                {{-- Qué rango se está viendo: el año en curso es un filtro
                     silencioso y hay que decirlo, o alguien busca un booking
                     viejo y cree que se perdió. --}}
                <span class="text-ink-faint">
                    · {{ $this->soloEsteAnio()
                        ? __('Creados en :anio', ['anio' => now()->year])
                        : ($dates || $loadingDates || $arrivalDates || $siDates ? __('Por el rango de fechas elegido') : __('Todos los años')) }}
                </span>
            </p>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-ink-faint">
                {{ $mode === '9'
                    ? trans_choice(__('{1}:count cotización|[0,*]:count cotizaciones'), $filas->total(), ['count' => number_format($filas->total())])
                    : trans_choice(__('{1}:count booking|[0,*]:count bookings'), $filas->total(), ['count' => number_format($filas->total())]) }}
                · {{ __('consulta en :ms ms', ['ms' => $queryMs]) }}
            </span>
            {{-- Dar de alta es de cualquier usuario interno, como en el original.
                 Con el tipo encendido, el alta ya llega con importación o
                 exportación elegida; la cotización, con su modo. --}}
            @if ($mode === '9')
                <a href="{{ route('operations.bookings.create', ['modo' => 'cotizacion']) }}" wire:navigate class="btn-accent !px-3 !py-1.5 text-xs">
                    {{ __('Nueva cotización') }}
                </a>
            @elseif ($verTipo)
                <a href="{{ route('operations.bookings.create', ['tipo' => \App\Models\Core\Booking::TYPE_IMPORT]) }}" wire:navigate class="btn-accent !px-3 !py-1.5 text-xs">
                    {{ __('Nueva importación') }}
                </a>
                <a href="{{ route('operations.bookings.create', ['tipo' => \App\Models\Core\Booking::TYPE_EXPORT]) }}" wire:navigate class="btn-accent !px-3 !py-1.5 text-xs">
                    {{ __('Nueva exportación') }}
                </a>
            @else
                <a href="{{ route('operations.bookings.create') }}" wire:navigate class="btn-accent !px-3 !py-1.5 text-xs">
                    {{ __('Nuevo booking') }}
                </a>
            @endif
        </div>
    </header>

    {{-- Filtros --}}
    <div class="card p-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="block">
                <span class="field-label text-xs">{{ __('Booking') }}</span>
                <input type="text" wire:model.live.debounce.400ms="bookingNumber" value="{{ $bookingNumber }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('MEX…') }}">
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Cliente') }}</span>
                <input type="text" wire:model.live.debounce.400ms="clientName" value="{{ $clientName }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('Nombre') }}">
            </label>

            @if ($verBuque)
                <label class="block">
                    <span class="field-label text-xs">{{ __('Buque') }}</span>
                    <input type="text" wire:model.live.debounce.400ms="vesselName" value="{{ $vesselName }}"
                           class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('Nombre') }}">
                </label>
            @endif

            {{-- El filtro solo existe si la instalación pide ese campo: buscar
                 por algo que el alta no captura es un filtro que nunca encuentra
                 nada, y el usuario no tiene manera de saber por qué. --}}
            @if (\App\Support\Expediente::visible('commodity'))
                <label class="block">
                    <span class="field-label text-xs">{{ __('Mercancía') }}</span>
                    <input type="text" wire:model.live.debounce.400ms="commodity" value="{{ $commodity }}"
                           class="field-input mt-1 py-1.5 text-sm" placeholder="{{ __('Descripción') }}">
                </label>
            @endif

            <label class="block">
                <span class="field-label text-xs">{{ __('Recolección') }} <span class="text-ink-faint">{{ __('(rango)') }}</span></span>
                <input type="text" wire:model.live.debounce.600ms="dates" value="{{ $dates }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="01/01/2025 - 31/12/2025">
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Carga') }} <span class="text-ink-faint">{{ __('(rango)') }}</span></span>
                <input type="text" wire:model.live.debounce.600ms="loadingDates" value="{{ $loadingDates }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="01/01/2025 - 31/12/2025">
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Arribo') }} <span class="text-ink-faint">{{ __('(rango)') }}</span></span>
                <input type="text" wire:model.live.debounce.600ms="arrivalDates" value="{{ $arrivalDates }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="01/01/2025 - 31/12/2025">
            </label>

            @if ($verBuque)
                <label class="block">
                    <span class="field-label text-xs">{{ __('Corte SI') }} <span class="text-ink-faint">{{ __('(rango)') }}</span></span>
                    <input type="text" wire:model.live.debounce.600ms="siDates" value="{{ $siDates }}"
                           class="field-input mt-1 py-1.5 text-sm" placeholder="01/01/2025 - 31/12/2025">
                </label>
            @endif

            <label class="block">
                <span class="field-label text-xs">{{ __('Puerto de descarga') }}</span>
                <select wire:model.live="dischargePort" class="field-input mt-1 py-1.5 text-sm">
                    <option value="">{{ __('Todos') }}</option>
                    @foreach ($puertosDescarga as $id => $nombre)
                        <option value="{{ $id }}" @selected((string) $id === $dischargePort)>{{ $nombre }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Lugar de recolección') }}</span>
                <select wire:model.live="pickupPlace" class="field-input mt-1 py-1.5 text-sm">
                    <option value="">{{ __('Todos') }}</option>
                    @foreach ($lugares as $id => $nombre)
                        <option value="{{ $id }}" @selected((string) $id === $pickupPlace)>{{ $nombre }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Ver') }}</span>
                <select wire:model.live="mode" class="field-input mt-1 py-1.5 text-sm">
                    @foreach (['10' => __('Bookings'), '9' => __('Cotizaciones')] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected((string) $valor === $mode)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>

            @if ($verTipo)
                <label class="block">
                    <span class="field-label text-xs">{{ __('Tipo') }}</span>
                    <select wire:model.live="bookingType" class="field-input mt-1 py-1.5 text-sm">
                        @foreach (['' => __('Todos'), '1' => __('Importaciones'), '2' => __('Exportaciones')] as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected((string) $valor === $bookingType)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <label class="block">
                <span class="field-label text-xs">{{ __('Borradores') }}</span>
                <select wire:model.live="drafts" class="field-input mt-1 py-1.5 text-sm">
                    @foreach (['0' => __('Sin borradores'), '1' => __('Solo borradores')] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected((string) $valor === $drafts)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="field-label text-xs">{{ __('Estado') }}</span>
                <select wire:model.live="onlyLocked" class="field-input mt-1 py-1.5 text-sm">
                    @foreach (['0' => __('Todos'), '1' => __('Solo cerrados')] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected((string) $valor === $onlyLocked)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button type="button" wire:click="clearFilters" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Limpiar filtros') }}</button>
            {{-- El año en curso se quita de un clic; y se vuelve a poner igual. --}}
            @if ($this->soloEsteAnio())
                <button type="button" wire:click="$set('allYears', '1')" class="text-xs text-brand hover:underline">
                    {{ __('Ver todos los años') }}
                </button>
            @elseif (! ($dates || $loadingDates || $arrivalDates || $siDates))
                <button type="button" wire:click="$set('allYears', '0')" class="text-xs text-brand hover:underline">
                    {{ __('Solo :anio', ['anio' => now()->year]) }}
                </button>
            @endif
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

    {{-- Resultados --}}
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
                <li class="space-y-2 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('operations.bookings.show', $fila->booking_id) }}" wire:navigate
                               class="font-semibold text-brand hover:underline">{{ trim((string) $fila->booking_number) ?: __('Sin número') }}</a>
                            <p class="truncate text-sm text-ink-muted">{{ $fila->client_name ?: '—' }}</p>
                        </div>
                        <span class="flex shrink-0 gap-1">
                            @if ($fila->is_draft)
                                <span class="badge badge-neutral">{{ __('Borrador') }}</span>
                            @endif
                            @if ($fila->locked)
                                <span class="badge badge-neutral">{{ __('Cerrado') }}</span>
                            @endif
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-raised">
                            <div class="h-full rounded-full {{ $tonoAvance((float) $fila->total_completed) }}"
                                 style="width: {{ min(100, (float) $fila->total_completed) }}%"></div>
                        </div>
                        <span class="shrink-0 text-xs tabular-nums text-ink-muted">{{ number_format((float) $fila->total_completed, 0) }}%</span>
                    </div>

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                        @if ($verBuque)
                            <div class="flex justify-between gap-2">
                                <dt class="text-ink-faint">{{ __('Buque') }}</dt>
                                <dd class="truncate text-ink-soft">{{ $fila->vessel_name ?: '—' }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-faint">ID</dt>
                            <dd class="text-ink-soft">{{ $fila->booking_id }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-faint">{{ __('Lugar de recolección') }}</dt>
                            <dd class="truncate text-ink-soft">{{ $fila->pickup_name ?: '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-ink-faint">{{ __('Carga') }}</dt>
                            <dd class="text-ink-soft">{{ $fecha($fila->loading_EDT) }}</dd>
                        </div>
                        @if ($verBuque)
                            <div class="flex justify-between gap-2">
                                <dt class="text-ink-faint">{{ __('Corte SI') }}</dt>
                                <dd class="text-ink-soft">{{ $fecha($fila->SI_date) }}</dd>
                            </div>
                        @endif
                        @if ($verTipo)
                            <div class="flex justify-between gap-2">
                                <dt class="text-ink-faint">{{ __('Tipo') }}</dt>
                                <dd class="text-ink-soft">{{ $tipos[(int) $fila->booking_type] ?? '—' }}</dd>
                            </div>
                        @endif
                    </dl>

                    {{-- Acciones (mismas que la tabla). «Continuidad» abre el
                         detalle en la lista de verificación, sin wire:navigate
                         para que el navegador respete el ancla. --}}
                    <div class="flex items-center gap-1 pt-1">
                        <a href="{{ route('operations.bookings.show', $fila->booking_id) }}#lista-de-verificacion"
                           title="{{ __('Continuidad') }}" class="booking-accion">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 5l7 7-7 7M13 5l7 7-7 7"/>
                            </svg>
                        </a>
                        <a href="{{ route('transactions.booking', $fila->booking_id) }}" wire:navigate
                           title="{{ __('Transacciones') }}" class="booking-accion">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16v12H4z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                            </svg>
                        </a>
                        @if (auth()->user()?->isAdmin() ?? false)
                            <a href="{{ route('operations.bookings.edit', $fila->booking_id) }}" wire:navigate
                               title="{{ __('Editar') }}" class="booking-accion">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 5.5l3 3M4 20l1-4L16.5 4.5a1.5 1.5 0 0 1 2 0l1 1a1.5 1.5 0 0 1 0 2L8 19l-4 1z"/>
                                </svg>
                            </a>
                            {{-- Un booking cerrado ya tiene su facturación fija:
                                 entrar a generarla solo daba un error. --}}
                            @if (! $fila->locked)
                                <a href="{{ route('operations.bookings.generate', $fila->booking_id) }}" wire:navigate
                                   title="{{ __('Generar factura') }}" class="booking-accion">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 3h7l4 4v14H7z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 3v5h5M10 13h5M10 17h5"/>
                                    </svg>
                                </a>
                            @endif
                        @endif
                        <a href="{{ route('operations.bookings.pdf', $fila->booking_id) }}" target="_blank"
                           title="{{ __('PDF de confirmación') }}" class="booking-accion">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v10m0 0l-3.5-3.5M12 13l3.5-3.5M5 17v2a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-2"/>
                            </svg>
                        </a>
                    </div>
                </li>
            @empty
                <li class="px-4 py-12 text-center text-sm text-ink-faint">{{ __('No hay embarques con estos filtros.') }}</li>
            @endforelse
        </ul>

        {{-- Tabla desde md --}}
        <div class="hidden overflow-x-auto md:block">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line bg-panel text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-3 py-2.5 text-left font-semibold">ID</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Booking') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Cliente') }}</th>
                        @if ($verTipo)
                            <th class="px-3 py-2.5 text-left font-semibold">{{ __('Tipo') }}</th>
                        @endif
                        @if ($verBuque)
                            <th class="px-3 py-2.5 text-left font-semibold">{{ __('Buque') }}</th>
                        @endif
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Origen') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Destino') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Lugar de recolección') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Recolección') }}</th>
                        @if ($verBuque)
                            <th class="px-3 py-2.5 text-left font-semibold">{{ __('Corte SI') }}</th>
                        @endif
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Carga') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Arribo') }}</th>
                        <th class="w-40 px-3 py-2.5 text-left font-semibold">{{ __('Avance') }}</th>
                        <th class="px-3 py-2.5 text-right font-semibold">{{ __('Acciones') }}</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    @forelse ($filas as $fila)
                        <tr class="transition hover:bg-raised">
                            <td class="whitespace-nowrap px-3 py-2 tabular-nums text-ink-muted">{{ $fila->booking_id }}</td>
                            <td class="whitespace-nowrap px-3 py-2">
                                <a href="{{ route('operations.bookings.show', $fila->booking_id) }}" wire:navigate
                                   class="font-medium text-brand hover:underline">{{ trim((string) $fila->booking_number) ?: '—' }}</a>
                                @if ($fila->is_draft)
                                    <span class="ml-1.5 badge badge-neutral">{{ __('Borrador') }}</span>
                                @endif
                                @if ($fila->locked)
                                    <span class="ml-1.5 badge badge-neutral">{{ __('Cerrado') }}</span>
                                @endif
                            </td>
                            <td class="max-w-[14rem] truncate px-3 py-2 text-ink-muted" title="{{ $fila->client_name }}">{{ $fila->client_name ?: '—' }}</td>
                            @if ($verTipo)
                                <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ $tipos[(int) $fila->booking_type] ?? '—' }}</td>
                            @endif
                            @if ($verBuque)
                                <td class="max-w-[12rem] truncate px-3 py-2 text-ink-muted" title="{{ $fila->vessel_name }}">{{ $fila->vessel_name ?: '—' }}</td>
                            @endif
                            <td class="max-w-[10rem] truncate px-3 py-2 text-ink-muted">{{ trim((string) $fila->port_name) ?: '—' }}</td>
                            <td class="max-w-[10rem] truncate px-3 py-2 text-ink-muted" title="{{ $fila->discharge_name }}">{{ $fila->discharge_name ?: '—' }}</td>
                            <td class="max-w-[10rem] truncate px-3 py-2 text-ink-muted" title="{{ $fila->pickup_name }}">{{ $fila->pickup_name ?: '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ $fecha($fila->pickup_date) }}</td>
                            @if ($verBuque)
                                <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ $fecha($fila->SI_date) }}</td>
                            @endif
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ $fecha($fila->loading_EDT) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ $fecha($fila->dicharge_ETA) }}</td>
                            <td class="px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-20 overflow-hidden rounded-full bg-raised">
                                        <div class="h-full rounded-full {{ $tonoAvance((float) $fila->total_completed) }}"
                                             style="width: {{ min(100, (float) $fila->total_completed) }}%"></div>
                                    </div>
                                    <span class="shrink-0 text-xs tabular-nums text-ink-muted">{{ number_format((float) $fila->total_completed, 0) }}%</span>
                                </div>
                            </td>
                            {{-- Acciones por fila, como en el sistema viejo: continuidad,
                                 transacciones, editar, generar factura y PDF. Editar/generar
                                 solo para administradores; ver, continuidad y PDF, para todos. --}}
                            <td class="whitespace-nowrap px-3 py-2 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <a href="{{ route('operations.bookings.show', $fila->booking_id) }}#lista-de-verificacion"
                                       title="{{ __('Continuidad') }}" class="booking-accion">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 5l7 7-7 7M13 5l7 7-7 7"/>
                                        </svg>
                                    </a>
                                    <a href="{{ route('transactions.booking', $fila->booking_id) }}" wire:navigate
                                       title="{{ __('Transacciones') }}" class="booking-accion">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16v12H4z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                                        </svg>
                                    </a>
                                    @if (auth()->user()?->isAdmin() ?? false)
                                        <a href="{{ route('operations.bookings.edit', $fila->booking_id) }}" wire:navigate
                                           title="{{ __('Editar') }}" class="booking-accion">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 5.5l3 3M4 20l1-4L16.5 4.5a1.5 1.5 0 0 1 2 0l1 1a1.5 1.5 0 0 1 0 2L8 19l-4 1z"/>
                                            </svg>
                                        </a>
                                        @if (! $fila->locked)
                                            <a href="{{ route('operations.bookings.generate', $fila->booking_id) }}" wire:navigate
                                               title="{{ __('Generar factura') }}" class="booking-accion">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 3h7l4 4v14H7z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 3v5h5M10 13h5M10 17h5"/>
                                                </svg>
                                            </a>
                                        @endif
                                    @endif
                                    <a href="{{ route('operations.bookings.pdf', $fila->booking_id) }}" target="_blank"
                                       title="{{ __('PDF de confirmación') }}" class="booking-accion">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v10m0 0l-3.5-3.5M12 13l3.5-3.5M5 17v2a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-2"/>
                                        </svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 12 + 2 * (int) $verBuque + (int) $verTipo }}" class="px-3 py-12 text-center text-ink-faint">{{ __('No hay embarques con estos filtros.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>{{ $filas->links() }}</div>
</div>
