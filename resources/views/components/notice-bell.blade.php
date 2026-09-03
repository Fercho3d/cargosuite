{{--
    Campana de avisos: cuántas cosas están esperando a alguien.

    Va en todas las páginas, así que el conteo sale de la caché del feed y, si la
    consulta falla, la campana se pinta vacía: es un adorno, no puede tumbar la
    pantalla que la lleva.
--}}
@php
    try {
        $pendientes = app(\App\Support\Notifications\NoticeFeed::class)->count();
    } catch (\Throwable $e) {
        $pendientes = 0;
    }
@endphp

<a href="{{ route('notifications') }}" wire:navigate
   class="relative flex h-9 w-9 items-center justify-center rounded-full text-ink-muted transition hover:bg-raised hover:text-ink"
   title="{{ __('Avisos') }}" aria-label="{{ __('Avisos') }}">
    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0a3 3 0 1 1-6 0m6 0H9"/>
    </svg>

    @if ($pendientes > 0)
        <span class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-accent-500 px-1 text-[10px] font-bold text-white">
            {{ $pendientes > 99 ? '99+' : $pendientes }}
        </span>
    @endif
</a>
