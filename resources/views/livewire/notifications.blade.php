@php
    $grupos = $this->grupos();
    $fecha = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
@endphp

<div class="mx-auto max-w-4xl space-y-4">

    <section class="card p-5 sm:p-6">
        <h2 class="text-xl font-semibold text-ink">{{ __('Avisos') }}</h2>
        <p class="mt-1 text-sm text-ink-muted">
            {{ __('Lo que está esperando a que alguien lo atienda. Se calcula al abrir: un aviso desaparece cuando el trabajo se hace.') }}
        </p>

        @if ($total > 0)
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" wire:click="$set('grupo', '')"
                        class="badge {{ $grupo === '' ? 'badge-ok' : 'badge-neutral' }}">
                    {{ __('Todos') }} · {{ $total }}
                </button>
                @foreach ($grupos as $clave => $etiqueta)
                    @continue (($conteos[$clave] ?? 0) === 0)
                    <button type="button" wire:click="$set('grupo', '{{ $clave }}')"
                            class="badge {{ $grupo === $clave ? 'badge-ok' : 'badge-neutral' }}">
                        {{ $etiqueta }} · {{ $conteos[$clave] }}
                    </button>
                @endforeach
            </div>
        @endif
    </section>

    @forelse ($avisos as $aviso)
        <a href="{{ $aviso->ruta }}" wire:navigate
           class="card flex items-center justify-between gap-4 p-4 transition hover:border-accent-500/40 hover:bg-raised">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="badge {{ $aviso->nivel === \App\Support\Notifications\Notice::URGENTE ? 'badge-danger' : 'badge-neutral' }}">
                        {{ $grupos[$aviso->grupo] ?? $aviso->grupo }}
                    </span>
                    <span class="truncate font-medium text-ink">{{ $aviso->titulo }}</span>
                </div>
                @if ($aviso->detalle !== '')
                    <p class="mt-0.5 truncate text-sm text-ink-muted">{{ $aviso->detalle }}</p>
                @endif
            </div>

            <span class="shrink-0 text-xs tabular-nums text-ink-faint">{{ $fecha($aviso->fecha) }}</span>
        </a>
    @empty
        <div class="card p-12 text-center">
            <p class="text-sm text-ink">{{ __('No hay nada pendiente.') }}</p>
            <p class="mt-1 text-xs text-ink-faint">{{ __('Cuando algo requiera atención, aparecerá aquí.') }}</p>
        </div>
    @endforelse
</div>
