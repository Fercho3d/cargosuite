@use('App\Livewire\Operations\ContinuityReport')

@php
    $hitos = ContinuityReport::hitos();
    $fecha = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m') : null;
    $esAdmin = auth()->user()?->isAdmin() ?? false;
@endphp

<div class="space-y-4">

    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-ink">{{ __('Continuidad') }}</h2>
            <p class="text-sm text-ink-muted">
                {{ __('En qué punto va cada embarque. Toca una celda para capturar la fecha del hito.') }}
            </p>
        </div>
        <span class="text-xs text-ink-faint">{{ number_format($filas->total()) }} embarques</span>
    </header>

    {{-- Filtros --}}
    <div class="card p-4">
        <div class="grid gap-3 sm:grid-cols-3">
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
            <label class="block">
                <span class="field-label text-xs">{{ __('Recolección') }} <span class="text-ink-faint">{{ __('(rango)') }}</span></span>
                <input type="text" wire:model.live.debounce.600ms="dates" value="{{ $dates }}"
                       class="field-input mt-1 py-1.5 text-sm" placeholder="01/01/2025 - 31/12/2025">
            </label>
        </div>

        <div class="mt-3">
            <button type="button" wire:click="clearFilters" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Limpiar filtros') }}</button>
        </div>
    </div>

    {{-- Rejilla --}}
    <div class="relative rounded-xl border border-line bg-panel">
        <div wire:loading.delay class="absolute inset-0 z-20 rounded-xl bg-panel/75 text-center backdrop-blur-[1px]">
            <span class="mt-14 inline-flex items-center gap-2 rounded-full border border-line bg-panel px-4 py-2 text-sm text-ink-muted shadow-lg">
                <x-spinner class="h-4 w-4 text-brand" /> Actualizando…
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="border-b border-line text-[11px] uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="sticky left-0 z-10 bg-panel px-3 py-2.5 text-left font-semibold">{{ __('Booking') }}</th>
                        <th class="px-3 py-2.5 text-left font-semibold">{{ __('Cliente') }}</th>
                        @foreach ($hitos as $etiqueta)
                            <th class="whitespace-nowrap px-2 py-2.5 text-center font-semibold">{{ $etiqueta }}</th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    @forelse ($filas as $fila)
                        <tr class="transition hover:bg-raised">
                            <td class="sticky left-0 z-10 whitespace-nowrap bg-panel px-3 py-2">
                                <a href="{{ route('operations.bookings.show', $fila->booking_id) }}" wire:navigate
                                   class="font-medium text-brand hover:underline">{{ trim((string) $fila->booking_number) ?: '—' }}</a>
                            </td>
                            <td class="max-w-[12rem] truncate px-3 py-2 text-ink-muted">{{ $fila->client_name ?: '—' }}</td>

                            @foreach ($hitos as $hito => $etiqueta)
                                @php $clave = $fila->booking_id.'|'.$hito; @endphp
                                <td class="whitespace-nowrap px-2 py-1 text-center">
                                    @if ($editing === $clave)
                                        <span class="inline-flex items-center gap-1">
                                            <input type="date" wire:model="value" value="{{ $value }}"
                                                   wire:keydown.enter="saveMilestone" wire:keydown.escape="cancel"
                                                   class="field-input !w-32 py-0.5 text-xs">
                                            <button type="button" wire:click="saveMilestone" class="text-brand" aria-label="{{ __('Guardar') }}">✓</button>
                                            <button type="button" wire:click="cancel" class="text-ink-faint" aria-label="{{ __('Cancelar') }}">×</button>
                                        </span>
                                    @elseif ($esAdmin)
                                        <button type="button"
                                                wire:click="editMilestone({{ $fila->booking_id }}, '{{ $hito }}', @js($fechas[$fila->booking_id][$hito] ?? null))"
                                                class="rounded px-2 py-1 transition hover:bg-line
                                                       {{ ($fechas[$fila->booking_id][$hito] ?? null) ? 'font-medium text-ink' : 'text-ink-faint' }}">
                                            {{ $fecha($fechas[$fila->booking_id][$hito] ?? null) ?? '·' }}
                                        </button>
                                    @else
                                        <span class="{{ ($fechas[$fila->booking_id][$hito] ?? null) ? 'text-ink' : 'text-ink-faint' }}">
                                            {{ $fecha($fechas[$fila->booking_id][$hito] ?? null) ?? '·' }}
                                        </span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($hitos) + 2 }}" class="px-3 py-12 text-center text-ink-faint">
                                {{ __('No hay embarques con estos filtros.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @error('value') <p class="alert-danger">{{ $message }}</p> @enderror

    <div>{{ $filas->links() }}</div>
</div>
