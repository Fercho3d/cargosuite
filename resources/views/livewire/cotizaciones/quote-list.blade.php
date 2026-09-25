@php
    $dinero = fn ($v) => '$'.number_format((float) $v, 2);
    $etiquetas = [
        'borrador' => [__('Borrador'), 'badge-neutral'],
        'enviada' => [__('Enviada'), 'badge-warn'],
        'vencida' => [__('Vencida'), 'badge-danger'],
        'aceptada' => [__('Aceptada'), 'badge-ok'],
        'rechazada' => [__('Rechazada'), 'badge-neutral'],
    ];
@endphp

<div class="space-y-4">
    <header class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-ink">{{ __('Cotizaciones') }}</h1>
            <p class="mt-0.5 text-sm text-ink-muted">
                {{ __('Se cotiza sobre una ruta configurada: los precios salen de ella y se ajustan en la cotización. Aceptada, se convierte en viaje y se factura con lo cotizado.') }}
            </p>
        </div>
        <button wire:click="$toggle('creando')"
                class="rounded-lg bg-accent-500 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-accent-600">
            {{ __('Nueva cotización') }}
        </button>
    </header>

    @include('partials.validation-errors')

    @if (session('status'))
        <div class="alert-ok rounded-lg px-3 py-2 text-sm">{{ session('status') }}</div>
    @endif

    @if ($creando)
        <div class="rounded-2xl border border-line bg-panel p-4">
            @if ($rutas === [])
                <p class="text-sm text-ink-muted">
                    {{ __('Primero configura una ruta con sus precios.') }}
                    <a href="{{ route('rutas.index') }}" wire:navigate class="text-brand hover:underline">{{ __('Configuración de rutas') }}</a>
                </p>
            @else
                <div class="grid gap-3 sm:grid-cols-6">
                    <label class="block sm:col-span-2">
                        <span class="field-label">{{ __('Cliente') }}</span>
                        <select wire:model.live="cliente" class="field-input mt-1.5">
                            <option value="">{{ __('Prospecto (no es cliente todavía)') }}</option>
                            @foreach ($clientes as $id => $nombre)
                                <option value="{{ $id }}" @selected((string) $id === $cliente)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if ($cliente === '')
                        <label class="block sm:col-span-2">
                            <span class="field-label">{{ __('Nombre del prospecto') }}</span>
                            <input type="text" wire:model="prospecto" value="{{ $prospecto }}" class="field-input mt-1.5">
                        </label>
                    @endif
                    <label class="block sm:col-span-2">
                        <span class="field-label">{{ __('Correo') }}</span>
                        <input type="email" wire:model="correo" value="{{ $correo }}" class="field-input mt-1.5"
                               placeholder="{{ $cliente === '' ? '' : __('El del cliente, si no pones otro') }}">
                    </label>
                    <label class="block sm:col-span-3">
                        <span class="field-label">{{ __('Ruta') }}</span>
                        <select wire:model="ruta" class="field-input mt-1.5">
                            <option value="">{{ __('Elige…') }}</option>
                            @foreach ($rutas as $id => $nombre)
                                <option value="{{ $id }}" @selected((string) $id === $ruta)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="field-label">{{ __('Fecha de carga') }}</span>
                        <input type="date" wire:model="fechaCarga" value="{{ $fechaCarga }}" class="field-input mt-1.5">
                    </label>
                    <div class="flex items-end">
                        <x-submit-button wire:click="crear">{{ __('Crear') }}</x-submit-button>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <div class="flex flex-wrap gap-1.5">
        @foreach (['' => __('Todas')] + collect($etiquetas)->map(fn ($e) => $e[0])->all() as $valor => $texto)
            <button wire:click="$set('estado', '{{ $valor }}')"
                    class="rounded-lg px-3 py-1.5 text-xs font-medium transition
                           {{ $estado === $valor ? 'bg-accent-500 text-white' : 'border border-line text-ink-muted hover:bg-raised hover:text-ink' }}">
                {{ $texto }}
            </button>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-2xl border border-line bg-panel">
        <table class="w-full min-w-[50rem] text-sm">
            <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                    @foreach ([__('Folio'), __('Cliente'), __('Ruta'), __('Vigencia'), __('Estado')] as $th)
                        <th class="px-3 py-2.5 text-left font-semibold">{{ $th }}</th>
                    @endforeach
                    <th class="px-3 py-2.5 text-right font-semibold">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($cotizaciones as $c)
                    @php [$texto, $clase] = $etiquetas[\App\Support\Cotizaciones\Cotizaciones::estado($c)]; @endphp
                    <tr class="transition hover:bg-raised">
                        <td class="px-3 py-2">
                            <a href="{{ route('cotizaciones.show', $c->cotizacion_id) }}" wire:navigate class="font-medium text-brand hover:underline">{{ $c->numero }}</a>
                        </td>
                        <td class="max-w-[16rem] truncate px-3 py-2 text-ink">{{ $c->cliente ?? $c->prospecto }}
                            @if (! $c->client_id) <span class="ml-1 text-xs text-ink-faint">({{ __('prospecto') }})</span> @endif
                        </td>
                        <td class="px-3 py-2 text-ink-muted">{{ $c->origen }} → {{ $c->destino }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ \Illuminate\Support\Carbon::parse($c->vigencia)->format('d/m/Y') }}</td>
                        <td class="px-3 py-2"><span class="{{ $clase }} rounded px-2 py-0.5 text-xs">{{ $texto }}</span></td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums text-ink">{{ $dinero($totales[$c->cotizacion_id] ?? 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-12 text-center text-ink-faint">{{ __('No hay cotizaciones.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $cotizaciones->links() }}
</div>
