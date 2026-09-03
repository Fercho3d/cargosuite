@php $verBuque = \App\Support\Expediente::visible('vesselId'); @endphp
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
            <h2 class="text-lg font-semibold text-ink">{{ $mode === '9' ? 'Cotizaciones' : 'Bookings' }}</h2>
            <p class="text-sm text-ink-muted">
                {{ __('Embarques y el avance de su lista de verificación.') }}
            </p>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-ink-faint">
                {{ number_format($filas->total()) }} {{ $mode === '9' ? 'cotizaciones' : 'bookings' }} · consulta en {{ $queryMs }} ms
            </span>
            @if (auth()->user()?->isAdmin())
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
                <span class="field-label text-xs">{{ __('Tipo') }}</span>
                <select wire:model.live="mode" class="field-input mt-1 py-1.5 text-sm">
                    @foreach (['10' => __('Bookings'), '9' => __('Cotizaciones')] as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected((string) $valor === $mode)>{{ $etiqueta }}</option>
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
                        @if ($fila->locked)
                            <span class="badge badge-neutral shrink-0">{{ __('Cerrado') }}</span>
                        @endif
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
                            <dt class="text-ink-faint">{{ __('Carga') }}</dt>
                            <dd class="text-ink-soft">{{ $fecha($fila->loading_EDT) }}</dd>
                        </div>
                    </dl>
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
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Booking') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Cliente') }}</th>
                        @if ($verBuque)
                            <th class="px-3 py-2.5 text-left font-semibold">{{ __('Buque') }}</th>
                        @endif
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Origen') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Destino') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Recolección') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Carga') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Arribo') }}</th>
                        <th class="w-40 px-3 py-2.5 text-left font-semibold">{{ __('Avance') }}</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    @forelse ($filas as $fila)
                        <tr class="transition hover:bg-raised">
                            <td class="whitespace-nowrap px-3 py-2">
                                <a href="{{ route('operations.bookings.show', $fila->booking_id) }}" wire:navigate
                                   class="font-medium text-brand hover:underline">{{ trim((string) $fila->booking_number) ?: '—' }}</a>
                                @if ($fila->locked)
                                    <span class="ml-1.5 badge badge-neutral">{{ __('Cerrado') }}</span>
                                @endif
                            </td>
                            <td class="max-w-[14rem] truncate px-3 py-2 text-ink-muted" title="{{ $fila->client_name }}">{{ $fila->client_name ?: '—' }}</td>
                            @if ($verBuque)
                                <td class="max-w-[12rem] truncate px-3 py-2 text-ink-muted" title="{{ $fila->vessel_name }}">{{ $fila->vessel_name ?: '—' }}</td>
                            @endif
                            <td class="max-w-[10rem] truncate px-3 py-2 text-ink-muted">{{ trim((string) $fila->port_name) ?: '—' }}</td>
                            <td class="max-w-[10rem] truncate px-3 py-2 text-ink-muted">{{ $fila->discharge_name ?: '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ $fecha($fila->pickup_date) }}</td>
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
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-3 py-12 text-center text-ink-faint">{{ __('No hay embarques con estos filtros.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>{{ $filas->links() }}</div>
</div>
