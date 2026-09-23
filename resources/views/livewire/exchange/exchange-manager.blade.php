@php
    $fecha = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
@endphp

<div class="space-y-4">

    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-ink">{{ __('Tipos de cambio') }}</h2>
            <p class="text-sm text-ink-muted">
                {{ __('Con esto se valúa cada documento. Un cambio aquí mueve cifras ya emitidas.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="accountId" class="field-input !w-auto py-1.5 text-sm">
                <option value="">{{ __('Todas las monedas') }}</option>
                @foreach ($monedas as $id => $prefijo)
                    <option value="{{ $id }}" @selected((string) $id === $accountId)>{{ $prefijo }}</option>
                @endforeach
            </select>

            <label class="flex items-center gap-1.5 text-xs text-ink-muted">
                {{ __('Desde') }}
                <input type="date" wire:model.live="from" value="{{ $from }}" class="field-input !w-auto py-1.5 text-sm">
            </label>
            <label class="flex items-center gap-1.5 text-xs text-ink-muted">
                {{ __('Hasta') }}
                <input type="date" wire:model.live="to" value="{{ $to }}" class="field-input !w-auto py-1.5 text-sm">
            </label>

            <button type="button" wire:click="fetchToday" wire:loading.attr="disabled" wire:target="fetchToday"
                    class="btn-ghost !px-3 !py-1.5 text-xs">
                <x-spinner wire:loading wire:target="fetchToday" class="h-3.5 w-3.5" />
                {{ __('Traer el del día (Banxico)') }}
            </button>

            <button type="button" wire:click="create" class="btn-accent !px-3 !py-1.5 text-xs">{{ __('Capturar') }}</button>
        </div>
    </header>

    @error('fetch') <p class="alert-danger">{{ $message }}</p> @enderror

    <p class="rounded-lg border border-line bg-raised px-4 py-3 text-xs text-ink-muted">
        <strong class="text-ink-soft">{{ __('Cómo se lee esta tabla.') }}</strong>
        {!! __('La fila que «aplica el día X» contiene el tipo de cambio publicado el día hábil <em>anterior</em>: es la convención del sistema desde siempre y no se cambió. El alta automática trae solo el <strong>dólar</strong> (FIX de Banxico, cada día hábil a las 07:30); el euro se captura a mano.') !!}
    </p>

    {{-- Formulario --}}
    @if ($editing !== null)
        <form wire:submit="save" class="card space-y-4 p-5">
            <p class="text-sm font-medium text-ink">{{ $editing === 0 ? __('Capturar tipo de cambio') : __('Editar tipo de cambio') }}</p>

            <div class="grid gap-4 sm:grid-cols-3">
                <label class="block">
                    <span class="field-label">{{ __('Aplica el día') }}</span>
                    <input type="date" wire:model="date" value="{{ $date }}" class="field-input mt-1.5" required>
                    @error('date') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="field-label">{{ __('Moneda') }}</span>
                    <select wire:model="account" class="field-input mt-1.5" required>
                        <option value="">{{ __('Selecciona') }}</option>
                        @foreach ($monedas as $id => $prefijo)
                            <option value="{{ $id }}" @selected((string) $id === $account)>{{ $prefijo }}</option>
                        @endforeach
                    </select>
                    @error('account') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="field-label">{{ __('Tipo de cambio') }}</span>
                    <input type="number" step="0.0001" min="0" wire:model="value" value="{{ $value }}"
                           class="field-input mt-1.5 text-right tabular-nums" required>
                    @error('value') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                </label>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-line pt-4">
                <button type="button" wire:click="cancel" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Cancelar') }}</button>
                <button type="submit" class="btn-accent !px-3 !py-1.5 text-xs">{{ __('Guardar') }}</button>
            </div>
        </form>
    @endif

    {{-- Listado --}}
    <div class="relative rounded-xl border border-line bg-panel">
        <div wire:loading.delay class="absolute inset-0 z-20 rounded-xl bg-panel/75 text-center backdrop-blur-[1px]">
            <span class="mt-14 inline-flex items-center gap-2 rounded-full border border-line bg-panel px-4 py-2 text-sm text-ink-muted shadow-lg">
                <x-spinner class="h-4 w-4 text-brand" /> {{ __('Actualizando…') }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Aplica el día') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Moneda') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Tipo de cambio') }}</th>
                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Publicado el') }}</th>
                        <th class="px-4 py-2.5 text-right font-semibold"><span class="sr-only">{{ __('Acciones') }}</span></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    @forelse ($tipos as $tipo)
                        <tr class="transition hover:bg-raised">
                            <td class="whitespace-nowrap px-4 py-2 text-ink">{{ $fecha($tipo->date_exchange) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ $monedas[$tipo->account] ?? $tipo->account }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums text-ink">
                                {{ number_format((float) $tipo->exchange_value, 4) }}
                            </td>
                            {{-- Solo lo que vino de Banxico tiene fecha de publicación; lo capturado a mano no --}}
                            <td class="whitespace-nowrap px-4 py-2 text-ink-faint">{{ $tipo->url ? $fecha($tipo->taken_date) : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                <button type="button" wire:click="edit({{ $tipo->exchange_id }})"
                                        class="text-xs text-brand hover:underline">{{ __('Editar') }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center text-ink-faint">{{ __('No hay tipos de cambio registrados.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>{{ $tipos->links() }}</div>
</div>
