@php $dinero = fn ($v) => '$'.number_format((float) $v, 2); @endphp

<div class="space-y-4">
    <header class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-ink">{{ __('Liquidaciones') }}</h1>
            <p class="mt-0.5 text-sm text-ink-muted">
                {{ __('Lo que se le paga a cada operador por sus viajes. No es nómina fiscal.') }}
            </p>
        </div>

        <button wire:click="$toggle('creando')"
                class="rounded-lg bg-accent-500 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-accent-600">
            {{ __('Nueva liquidación') }}
        </button>
    </header>

    @include('partials.validation-errors')

    @if (session('status'))
        <div class="alert-ok rounded-lg px-3 py-2 text-sm">{{ session('status') }}</div>
    @endif

    @if ($creando)
        <div class="rounded-2xl border border-line bg-panel p-4">
            <p class="text-sm font-medium text-ink">{{ __('Nueva liquidación') }}</p>
            <p class="mt-1 text-xs text-ink-muted">
                {{ __('Se traen los viajes del operador en el periodo que todavía no están en otra liquidación.') }}
            </p>

            <div class="mt-3 grid gap-3 sm:grid-cols-4">
                <label class="block">
                    <span class="field-label">{{ __('Operador') }}</span>
                    <select wire:model="nuevoOperador" class="field-input mt-1.5">
                        <option value="">{{ __('Selecciona') }}</option>
                        @foreach ($operadores as $id => $nombre)
                            <option value="{{ $id }}" @selected((string) $id === $nuevoOperador)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="field-label">{{ __('Desde') }}</span>
                    <input type="date" wire:model="desde" value="{{ $desde }}" class="field-input mt-1.5">
                </label>
                <label class="block">
                    <span class="field-label">{{ __('Hasta') }}</span>
                    <input type="date" wire:model="hasta" value="{{ $hasta }}" class="field-input mt-1.5">
                </label>
                <div class="flex items-end">
                    <x-submit-button wire:click="crear">{{ __('Crear') }}</x-submit-button>
                </div>
            </div>
        </div>
    @endif

    <div class="flex flex-wrap gap-3">
        <label class="block">
            <span class="field-label text-xs">{{ __('Operador') }}</span>
            <select wire:model.live="operadorFiltro" class="field-input mt-1 py-1.5 text-sm">
                <option value="">{{ __('Todos') }}</option>
                @foreach ($operadores as $id => $nombre)
                    <option value="{{ $id }}" @selected((string) $id === $operadorFiltro)>{{ $nombre }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">
            <span class="field-label text-xs">{{ __('Estado') }}</span>
            <select wire:model.live="estadoFiltro" class="field-input mt-1 py-1.5 text-sm">
                <option value="">{{ __('Todos') }}</option>
                <option value="abierta" @selected($estadoFiltro === 'abierta')>{{ __('Abiertas') }}</option>
                <option value="pagada" @selected($estadoFiltro === 'pagada')>{{ __('Pagadas') }}</option>
            </select>
        </label>
    </div>

    <div class="overflow-x-auto rounded-2xl border border-line bg-panel">
        <table class="w-full min-w-[46rem] text-sm">
            <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                    @foreach ([__('Folio'), __('Operador'), __('Periodo'), __('Estado'), ''] as $th)
                        <th class="px-3 py-2.5 text-left font-semibold">{{ $th }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($liquidaciones as $l)
                    <tr class="transition hover:bg-raised">
                        <td class="px-3 py-2">
                            <button wire:click="ver({{ $l->liquidacion_id }})"
                                    class="font-medium text-brand hover:underline">{{ $l->numero }}</button>
                        </td>
                        <td class="px-3 py-2 text-ink">{{ $l->operador ?: '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-ink-muted">
                            {{ \Illuminate\Support\Carbon::parse($l->desde)->format('d/m/Y') }}
                            – {{ \Illuminate\Support\Carbon::parse($l->hasta)->format('d/m/Y') }}
                        </td>
                        <td class="px-3 py-2">
                            <span class="{{ $l->estado === 'pagada' ? 'badge-ok' : 'badge-warn' }} rounded px-2 py-0.5 text-xs">
                                {{ $l->estado === 'pagada' ? __('Pagada') : __('Abierta') }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            @if ($l->estado === 'abierta')
                                <button wire:click="pagar({{ $l->liquidacion_id }})"
                                        wire:confirm="{{ __('¿Marcar esta liquidación como pagada? Después ya no se podrá modificar.') }}"
                                        class="rounded-lg border border-line px-2.5 py-1 text-xs text-ink-soft transition hover:bg-raised">
                                    {{ __('Marcar pagada') }}
                                </button>
                            @elseif (auth()->user()?->isSuperAdmin())
                                <button wire:click="reabrir({{ $l->liquidacion_id }})"
                                        class="rounded-lg border border-line px-2.5 py-1 text-xs text-ink-soft transition hover:bg-raised">
                                    {{ __('Reabrir') }}
                                </button>
                            @endif
                        </td>
                    </tr>

                    @if ($abierta === $l->liquidacion_id)
                        <tr>
                            <td colspan="5" class="bg-raised px-3 py-4">
                                <table class="w-full text-sm">
                                    <tbody class="divide-y divide-line">
                                        @foreach ($renglones as $r)
                                            <tr>
                                                <td class="py-1.5 pr-3 text-ink">{{ $r->concepto }}</td>
                                                <td class="w-28 py-1.5 pr-3 text-xs text-ink-faint">
                                                    {{ $r->tipo === 'deduccion' ? __('Deducción') : __('Percepción') }}
                                                </td>
                                                <td class="w-36 py-1.5 pr-3 text-right tabular-nums {{ $r->tipo === 'deduccion' ? 'text-brand' : 'text-ink' }}">
                                                    {{ $r->tipo === 'deduccion' ? '−' : '' }}{{ $dinero($r->importe) }}
                                                </td>
                                                <td class="w-10 py-1.5 text-right">
                                                    @if ($l->estado === 'abierta')
                                                        <button wire:click="quitarRenglon({{ $r->renglon_id }})"
                                                                class="text-xs text-ink-faint hover:text-brand">×</button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot class="border-t border-line">
                                        <tr>
                                            <td colspan="2" class="pt-2 text-xs text-ink-muted">
                                                {{ __('Percepciones') }} {{ $dinero($totales['percepciones']) }} ·
                                                {{ __('Deducciones') }} {{ $dinero($totales['deducciones']) }}
                                            </td>
                                            <td class="pt-2 text-right text-base font-semibold tabular-nums text-ink">
                                                {{ $dinero($totales['total']) }}
                                            </td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>

                                @if ($l->estado === 'abierta')
                                    <div class="mt-4 grid gap-2 sm:grid-cols-4">
                                        <input type="text" wire:model="concepto" value="{{ $concepto }}"
                                               class="field-input sm:col-span-2" placeholder="{{ __('Concepto') }}">
                                        <select wire:model="tipo" class="field-input">
                                            <option value="percepcion" @selected($tipo === 'percepcion')>{{ __('Percepción') }}</option>
                                            <option value="deduccion" @selected($tipo === 'deduccion')>{{ __('Deducción') }}</option>
                                        </select>
                                        <div class="flex gap-2">
                                            <input type="number" step="0.01" wire:model="importe" value="{{ $importe }}"
                                                   class="field-input" placeholder="0.00">
                                            <button wire:click="agregarRenglon"
                                                    class="shrink-0 rounded-lg border border-line px-3 text-sm text-ink-soft transition hover:bg-panel">
                                                {{ __('Agregar') }}
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="5" class="px-3 py-12 text-center text-ink-faint">{{ __('No hay liquidaciones.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $liquidaciones->links() }}
</div>
