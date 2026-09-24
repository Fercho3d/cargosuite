<div class="space-y-4">
    <header class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-ink">{{ __('Nómina') }}</h1>
            <p class="mt-0.5 text-sm text-ink-muted">
                {{ __('Lo que se le paga a la plantilla en el periodo, con impuestos y cuotas según el régimen de cada empleado. Ya pagada, se timbra el recibo de cada empleado.') }}
            </p>
            <p class="mt-1 flex flex-wrap gap-x-4 text-xs">
                <a href="{{ route('catalogs.show', 'empleados') }}" wire:navigate class="text-brand hover:underline">{{ __('Empleados') }}</a>
                <a href="{{ route('catalogs.show', 'tablas-fiscales') }}" wire:navigate class="text-brand hover:underline">{{ __('Tablas fiscales') }}</a>
                <a href="{{ route('catalogs.show', 'parametros-fiscales') }}" wire:navigate class="text-brand hover:underline">{{ __('Parámetros fiscales') }}</a>
            </p>
        </div>

        <button wire:click="$toggle('creando')"
                class="rounded-lg bg-accent-500 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-accent-600">
            {{ __('Nueva nómina') }}
        </button>
    </header>

    @include('partials.validation-errors')

    @if (session('status'))
        <div class="alert-ok rounded-lg px-3 py-2 text-sm">{{ session('status') }}</div>
    @endif

    @if ($creando)
        <div class="rounded-2xl border border-line bg-panel p-4">
            <p class="text-sm font-medium text-ink">{{ __('Nueva nómina') }}</p>
            <p class="mt-1 text-xs text-ink-muted">
                {{ __('Se propone el sueldo de cada empleado por los días del periodo, más las liquidaciones de viaje que aún no se han pagado en otra nómina. Entran los empleados de esta periodicidad y los que no tienen una.') }}
            </p>

            <div class="mt-3 grid gap-3 sm:grid-cols-4">
                <label class="block">
                    <span class="field-label">{{ __('Desde') }}</span>
                    <input type="date" wire:model="desde" value="{{ $desde }}" class="field-input mt-1.5">
                </label>
                <label class="block">
                    <span class="field-label">{{ __('Hasta') }}</span>
                    <input type="date" wire:model="hasta" value="{{ $hasta }}" class="field-input mt-1.5">
                </label>
                <label class="block">
                    <span class="field-label">{{ __('Periodicidad') }}</span>
                    <select wire:model="periodicidad" class="field-input mt-1.5">
                        <option value="semanal" @selected($periodicidad === 'semanal')>{{ __('Semanal') }}</option>
                        <option value="catorcenal" @selected($periodicidad === 'catorcenal')>{{ __('Catorcenal') }}</option>
                        <option value="quincenal" @selected($periodicidad === 'quincenal')>{{ __('Quincenal') }}</option>
                        <option value="mensual" @selected($periodicidad === 'mensual')>{{ __('Mensual') }}</option>
                    </select>
                </label>
                <div class="flex items-end">
                    <x-submit-button wire:click="crear">{{ __('Crear') }}</x-submit-button>
                </div>
            </div>
        </div>
    @endif

    <div class="overflow-x-auto rounded-2xl border border-line bg-panel">
        <table class="w-full min-w-[46rem] text-sm">
            <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                <tr>
                    @foreach ([__('Folio'), __('Periodo'), __('Periodicidad'), __('Estado'), ''] as $th)
                        <th class="px-3 py-2.5 text-left font-semibold">{{ $th }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($nominas as $n)
                    @php
                        $pagadosN = (int) ($pagados[$n->nomina_id] ?? 0);
                        $totalN = (int) ($empleadosPorNomina[$n->nomina_id] ?? 0);
                    @endphp
                    <tr class="transition hover:bg-raised">
                        <td class="px-3 py-2">
                            <a href="{{ route('payments.payroll.show', $n->nomina_id) }}" wire:navigate
                               class="font-medium text-brand hover:underline">{{ $n->numero }}</a>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-ink-muted">
                            {{ \Illuminate\Support\Carbon::parse($n->desde)->format('d/m/Y') }}
                            – {{ \Illuminate\Support\Carbon::parse($n->hasta)->format('d/m/Y') }}
                        </td>
                        <td class="px-3 py-2 text-ink-soft">
                            {{ __(ucfirst($n->periodicidad)) }}
                        </td>
                        <td class="px-3 py-2">
                            @if ($n->estado === 'pagada')
                                <span class="badge-ok rounded px-2 py-0.5 text-xs">{{ __('Pagada') }}</span>
                            @elseif ($pagadosN > 0)
                                <span class="badge-warn rounded px-2 py-0.5 text-xs">{{ __('Pagados :n de :total', ['n' => $pagadosN, 'total' => $totalN]) }}</span>
                            @else
                                <span class="badge-warn rounded px-2 py-0.5 text-xs">{{ __('Abierta') }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right">
                            <a href="{{ route('payments.payroll.show', $n->nomina_id) }}" wire:navigate
                               class="rounded-lg border border-line px-2.5 py-1 text-xs text-ink-soft transition hover:bg-raised">
                                {{ __('Abrir') }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-3 py-12 text-center text-ink-faint">{{ __('No hay nóminas.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $nominas->links() }}
</div>
