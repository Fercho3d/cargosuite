@php
    $esAdmin = auth()->user()?->isAdmin() ?? false;

    // En el listado solo las columnas que sirven para reconocer al tercero.
    $enLista = $this->listFields();
    $tipos = $this->optionsFor('type_id');
@endphp

<div class="space-y-4">

    <nav class="flex flex-wrap gap-1.5">
        @foreach (['client' => __('Clientes'), 'provider' => __('Proveedores')] as $modo => $etiqueta)
            <a href="{{ route($modo === 'client' ? 'parties.clients' : 'parties.providers') }}" wire:navigate
               class="rounded-lg px-3 py-1.5 text-xs font-medium transition
                      {{ $mode === $modo ? 'bg-accent-500 text-white' : 'border border-line text-ink-muted hover:bg-raised hover:text-ink' }}">
                {{ $etiqueta }}
            </a>
        @endforeach
    </nav>

    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-ink">{{ $this->title() }}</h2>
            <p class="text-sm text-ink-muted">
                {{ $this->isClient()
                    ? __('Los datos fiscales de aquí son los que viajan al CFDI.')
                    : __('El tipo decide en qué selector del booking aparece cada proveedor.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <input type="search" wire:model.live.debounce.300ms="search" value="{{ $search }}"
                   class="field-input py-1.5 text-sm" placeholder="{{ __('Buscar por nombre, RFC, correo o ciudad…') }}">
            @if ($esAdmin)
                <button type="button" wire:click="export" wire:loading.attr="disabled" wire:target="export"
                        class="btn-ghost !px-3 !py-1.5 text-xs">
                    <x-spinner wire:loading wire:target="export" class="h-3.5 w-3.5" />
                    {{ __('Exportar CSV') }}
                </button>
                <a href="{{ route($this->listRoute().'.create', ['volver' => $this->currentUrl()]) }}" wire:navigate
                   class="btn-accent !px-3 !py-1.5 text-xs">{{ __('Agregar') }}</a>
            @endif
        </div>
    </header>

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
                        @foreach ($enLista as [$etiqueta, $tipo, $reglas])
                            <th class="px-4 py-2.5 text-left font-semibold">{{ $etiqueta }}</th>
                        @endforeach
                        @if ($esAdmin)
                            <th class="px-4 py-2.5 text-right font-semibold"><span class="sr-only">{{ __('Acciones') }}</span></th>
                        @endif
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    @forelse ($filas as $fila)
                        <tr class="transition hover:bg-raised">
                            @foreach ($enLista as $campo => $definicion)
                                <td class="max-w-[18rem] truncate px-4 py-2 {{ $loop->first ? 'text-ink' : 'text-ink-muted' }}"
                                    title="{{ $fila->{$campo} }}">
                                    @if ($campo === 'type_id')
                                        @if (isset($tipos[$fila->type_id]))
                                            <span class="badge badge-neutral">{{ $tipos[$fila->type_id] }}</span>
                                        @else
                                            <span class="badge badge-warn" title="{{ __('Sin tipo no aparece en el booking') }}">{{ __('Sin tipo') }}</span>
                                        @endif
                                    @else
                                        {{ $fila->{$campo} ?: '—' }}
                                    @endif
                                </td>
                            @endforeach
                            @if ($esAdmin)
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    <a href="{{ route($this->listRoute().'.edit', [$fila->{$this->key()}, 'volver' => $this->currentUrl()]) }}" wire:navigate
                                       class="text-xs text-brand hover:underline">{{ __('Editar') }}</a>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($enLista) + 1 }}" class="px-4 py-12 text-center text-ink-faint">
                                {{ $search === '' ? __('No hay registros.') : __('Nada coincide con la búsqueda.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>{{ $filas->links() }}</div>
</div>
