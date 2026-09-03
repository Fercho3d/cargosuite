<div class="space-y-4">
    <header class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-ink">{{ __('Solicitudes de demostración') }}</h1>
            <p class="mt-0.5 text-sm text-ink-muted">{{ __('Lo que llega por la página pública.') }}</p>
        </div>

        <label class="flex items-center gap-2 text-sm text-ink-muted">
            <input type="checkbox" wire:model.live="soloPendientes" class="h-4 w-4 rounded border-line">
            {{ __('Solo pendientes') }} ({{ $pendientes }})
        </label>
    </header>

    <div class="overflow-x-auto rounded-2xl border border-line bg-panel">
        <table class="w-full min-w-[48rem] text-sm">
            <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                    @foreach ([__('Fecha'), __('Nombre'), __('Empresa'), __('Contacto'), __('Mensaje'), ''] as $encabezado)
                        <th class="px-3 py-2.5 text-left font-semibold">{{ $encabezado }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($solicitudes as $s)
                    <tr class="{{ $s->atendida ? 'opacity-50' : '' }}">
                        <td class="whitespace-nowrap px-3 py-2 text-ink-muted">
                            {{ \Illuminate\Support\Carbon::parse($s->created_at)->format('d/m/Y H:i') }}
                        </td>
                        <td class="px-3 py-2 font-medium text-ink">{{ $s->nombre }}</td>
                        <td class="px-3 py-2 text-ink-muted">{{ $s->empresa ?: '—' }}</td>
                        <td class="px-3 py-2">
                            <a href="mailto:{{ $s->correo }}" class="text-brand hover:underline">{{ $s->correo }}</a>
                            @if ($s->telefono) <span class="block text-xs text-ink-faint">{{ $s->telefono }}</span> @endif
                        </td>
                        <td class="max-w-sm px-3 py-2 text-ink-muted">{{ $s->mensaje ?: '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <button wire:click="alternar({{ $s->solicitud_id }})"
                                    class="rounded-lg border border-line px-2.5 py-1 text-xs text-ink-soft transition hover:bg-raised">
                                {{ $s->atendida ? __('Reabrir') : __('Marcar atendida') }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-3 py-12 text-center text-ink-faint">
                            {{ __('No hay solicitudes.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $solicitudes->links() }}
</div>
