@php
    $color = [
        'CREATE' => 'badge-ok',
        'UPDATE' => 'badge-neutral',
        'DELETED' => 'badge-danger',
    ];
    $etiqueta = ['CREATE' => 'Alta', 'UPDATE' => 'Cambio', 'DELETED' => 'Baja'];
@endphp

<div class="mx-auto max-w-4xl space-y-4">

    <a href="{{ route('operations.bookings.show', $booking->booking_id) }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-ink">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        {{ __('Volver al booking') }}
    </a>

    <section class="card p-5 sm:p-6">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-faint">{{ __('Historial') }}</p>
        <h2 class="mt-0.5 text-2xl font-semibold text-ink">
            {{ trim((string) $booking->booking_number) ?: 'Booking '.$booking->booking_id }}
        </h2>
        <p class="mt-1 text-sm text-ink-muted">
            {{ $total }} {{ $total === 1 ? 'movimiento registrado' : 'movimientos registrados' }}.
            {{ __('Lo escribe la propia base de datos cada vez que algo cambia.') }}
        </p>

        @if ($origenes->count() > 1)
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" wire:click="$set('origen', '')"
                        class="badge {{ $origen === '' ? 'badge-ok' : 'badge-neutral' }}">Todo</button>
                @foreach ($origenes as $nombre)
                    <button type="button" wire:click="$set('origen', '{{ $nombre }}')"
                            class="badge {{ $origen === $nombre ? 'badge-ok' : 'badge-neutral' }}">{{ $nombre }}</button>
                @endforeach
            </div>
        @endif
    </section>

    @forelse ($eventos as $evento)
        <section class="card p-4 sm:p-5">
            <header class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="badge {{ $color[$evento['tipo']] ?? 'badge-neutral' }}">
                        {{ $etiqueta[$evento['tipo']] ?? $evento['tipo'] }}
                    </span>
                    <span class="text-sm font-medium text-ink">{{ $evento['origen'] }}</span>
                </div>
                <div class="text-xs text-ink-faint">
                    {{ \Illuminate\Support\Carbon::parse($evento['fecha'])->format('d/m/Y H:i') }}
                    @if ($evento['usuario'])
                        · {{ $evento['usuario'] }}
                    @endif
                </div>
            </header>

            @if ($evento['cambios'] === [])
                <p class="mt-3 text-sm text-ink-faint">{{ __('Sin cambios registrados en este movimiento.') }}</p>
            @else
                <dl class="mt-3 space-y-1.5 text-sm">
                    @foreach ($evento['cambios'] as $cambio)
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <dt class="text-ink-muted">{{ $cambio['campo'] }}:</dt>
                            <dd class="flex flex-wrap items-baseline gap-x-2">
                                @if ($cambio['antes'] !== null)
                                    <span class="text-ink-faint line-through">{{ $cambio['antes'] }}</span>
                                    <span class="text-ink-faint">→</span>
                                @endif
                                <span class="text-ink">{{ $cambio['despues'] ?? '—' }}</span>
                            </dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </section>
    @empty
        <div class="card p-10 text-center text-sm text-ink-faint">
            {{ __('Este booking no tiene movimientos registrados.') }}
        </div>
    @endforelse
</div>
