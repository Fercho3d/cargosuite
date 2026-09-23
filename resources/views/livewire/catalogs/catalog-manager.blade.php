<div class="space-y-4">

    {{-- Selector de catálogo: los dieciséis viven en la misma pantalla --}}
    <nav class="flex flex-wrap gap-1.5">
        @foreach ($catalogos as $otro)
            <a href="{{ route('catalogs.show', $otro->slug) }}" wire:navigate
               class="rounded-lg px-3 py-1.5 text-xs font-medium transition
                      {{ $otro->slug === $slug ? 'bg-accent-500 text-white' : 'border border-line text-ink-muted hover:bg-raised hover:text-ink' }}">
                {{ $otro->plural }}
            </a>
        @endforeach
    </nav>

    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-ink">{{ $definicion->plural }}</h2>
            @if ($definicion->note)
                <p class="text-sm text-ink-muted">{{ $definicion->note }}</p>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <label class="relative">
                <span class="sr-only">{{ __('Buscar') }}</span>
                <input type="search" wire:model.live.debounce.300ms="search" value="{{ $search }}"
                       class="field-input py-1.5 pl-8 text-sm" placeholder="{{ __('Buscar…') }}">
                <svg class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/>
                </svg>
            </label>

            @if (auth()->user()?->isAdmin())
                <button type="button" wire:click="create" class="btn-accent !px-3 !py-1.5 text-xs">
                    {{ __('Agregar') }}
                </button>
            @endif
        </div>
    </header>

    @error('delete')
        <p class="alert-danger">{{ $message }}</p>
    @enderror

    {{-- Formulario --}}
    @if ($editing !== null)
        <form wire:submit="save" class="card space-y-4 p-5">
            <p class="text-sm font-medium text-ink">
                {{ $editing === 0 ? __('Nuevo :cosa', ['cosa' => mb_strtolower($definicion->singular)]) : __('Editar :cosa', ['cosa' => mb_strtolower($definicion->singular)]) }}
            </p>

            @error('form')
                <p class="alert-danger">{{ $message }}</p>
            @enderror

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($definicion->fields as $campo)
                    @continue(! $campo->visible($form))
                    <label class="block {{ $campo->isBoolean() ? 'sm:col-span-2' : '' }}">
                        @if ($campo->isBoolean())
                            {{-- En vivo: una casilla puede ocultar otros campos («no deducible» quita los impuestos) --}}
                            <span class="flex items-center gap-2 text-sm text-ink-soft">
                                <input type="checkbox" wire:model.live="form.{{ $campo->name }}"
                                       @checked($form[$campo->name] ?? false)
                                       class="h-4 w-4 rounded border-line bg-panel text-accent-500 focus:ring-accent-500">
                                {{ $campo->label }}
                            </span>
                        @elseif ($campo->type === 'select')
                            <span class="field-label">{{ $campo->label }}</span>
                            <select wire:model="form.{{ $campo->name }}" class="field-input mt-1.5">
                                <option value="">{{ __('Ninguno') }}</option>
                                @foreach ($campo->opciones() as $valor => $etiqueta)
                                    <option value="{{ $valor }}" @selected((string) ($form[$campo->name] ?? '') === (string) $valor)>{{ $etiqueta }}</option>
                                @endforeach
                            </select>
                        @else
                            <span class="field-label">{{ $campo->label }}</span>
                            <input type="{{ $campo->type === 'date' ? 'date' : ($campo->type === 'number' ? 'number' : 'text') }}"
                                   @if ($campo->type === 'number') step="0.0001" @endif
                                   wire:model="form.{{ $campo->name }}" value="{{ $form[$campo->name] ?? '' }}"
                                   class="field-input mt-1.5">
                        @endif

                        @error('form.'.$campo->name)
                            <span class="mt-1 block text-xs text-brand">{{ $message }}</span>
                        @enderror
                    </label>
                @endforeach
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-line pt-4">
                <button type="button" wire:click="cancel" class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Cancelar') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="btn-accent !px-3 !py-1.5 text-xs">
                    <x-spinner wire:loading wire:target="save" class="h-3.5 w-3.5" />
                    {{ __('Guardar') }}
                </button>
            </div>
        </form>
    @endif

    {{-- Listado --}}
    <div class="relative rounded-xl border border-line bg-panel">
        <div wire:loading.delay class="absolute inset-0 z-20 rounded-xl bg-panel/75 text-center backdrop-blur-[1px]">
            <span class="mt-14 inline-flex items-center gap-2 rounded-full border border-line bg-panel px-4 py-2 text-sm text-ink-muted shadow-lg">
                <x-spinner class="h-4 w-4 text-brand" />
                {{ __('Actualizando…') }}
            </span>
        </div>

        {{-- Tarjetas en móvil --}}
        <ul class="divide-y divide-line md:hidden">
            @forelse ($filas as $fila)
                <li class="space-y-1.5 p-4">
                    @foreach ($definicion->listFields() as $i => $campo)
                        <div class="flex justify-between gap-3 {{ $i === 0 ? 'text-sm font-medium text-ink' : 'text-xs' }}">
                            @if ($i > 0)<span class="text-ink-faint">{{ $campo->label }}</span>@endif
                            <span class="{{ $i === 0 ? '' : 'text-ink-muted' }}">
                                @if ($campo->isBoolean())
                                    <span class="badge {{ $fila->{$campo->name} ? 'badge-ok' : 'badge-neutral' }}">
                                        {{ $fila->{$campo->name} ? __('Sí') : __('No') }}
                                    </span>
                                @else
                                    {{ $fila->{$campo->name} ?: '—' }}
                                @endif
                            </span>
                        </div>
                    @endforeach
                    @foreach ($definicion->badges as $etiqueta => $calcular)
                        @php [$texto, $bien] = $calcular($fila); @endphp
                        <div class="flex justify-between gap-3 text-xs">
                            <span class="text-ink-faint">{{ $etiqueta }}</span>
                            <span class="badge {{ $bien ? 'badge-ok' : 'badge-warn' }}">{{ $texto }}</span>
                        </div>
                    @endforeach

                    @if (auth()->user()?->isAdmin())
                        <div class="flex gap-3 pt-1 text-xs">
                            <button type="button" wire:click="edit({{ $fila->{$definicion->key} }})" class="text-brand hover:underline">{{ __('Editar') }}</button>
                            <button type="button" wire:click="delete({{ $fila->{$definicion->key} }})"
                                    wire:confirm="{{ __('¿Dar de baja este registro?') }}" class="text-ink-muted hover:text-brand">{{ __('Baja') }}</button>
                        </div>
                    @endif
                </li>
            @empty
                <li class="px-4 py-12 text-center text-sm text-ink-faint">
                    {{ $search === '' ? __('Este catálogo está vacío.') : __('Nada coincide con la búsqueda.') }}
                </li>
            @endforelse
        </ul>

        {{-- Tabla desde md --}}
        <div class="hidden overflow-x-auto md:block">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        @foreach ($definicion->listFields() as $campo)
                            <th class="px-4 py-2.5 text-left font-semibold">{{ $campo->label }}</th>
                        @endforeach
                        @foreach ($definicion->badges as $etiqueta => $calcular)
                            <th class="px-4 py-2.5 text-left font-semibold">{{ $etiqueta }}</th>
                        @endforeach
                        @if (auth()->user()?->isAdmin())
                            <th class="px-4 py-2.5 text-right font-semibold"><span class="sr-only">{{ __('Acciones') }}</span></th>
                        @endif
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    @forelse ($filas as $fila)
                        <tr class="transition hover:bg-raised {{ $editing === (int) $fila->{$definicion->key} ? 'bg-raised' : '' }}">
                            @foreach ($definicion->listFields() as $campo)
                                <td class="px-4 py-2 {{ $campo->type === 'number' ? 'text-right tabular-nums' : '' }} text-ink-soft">
                                    @if ($campo->isBoolean())
                                        <span class="badge {{ $fila->{$campo->name} ? 'badge-ok' : 'badge-neutral' }}">
                                            {{ $fila->{$campo->name} ? __('Sí') : __('No') }}
                                        </span>
                                    @else
                                        {{ $fila->{$campo->name} === null || $fila->{$campo->name} === '' ? '—' : $fila->{$campo->name} }}
                                    @endif
                                </td>
                            @endforeach
                            @foreach ($definicion->badges as $calcular)
                                @php [$texto, $bien] = $calcular($fila); @endphp
                                <td class="px-4 py-2">
                                    <span class="badge {{ $bien ? 'badge-ok' : 'badge-warn' }}">{{ $texto }}</span>
                                </td>
                            @endforeach

                            @if (auth()->user()?->isAdmin())
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    <div class="flex justify-end gap-3 text-xs">
                                        <button type="button" wire:click="edit({{ $fila->{$definicion->key} }})" class="text-brand hover:underline">{{ __('Editar') }}</button>
                                        <button type="button" wire:click="delete({{ $fila->{$definicion->key} }})"
                                                wire:confirm="{{ __('¿Dar de baja este registro?') }}" class="text-ink-muted transition hover:text-brand">{{ __('Baja') }}</button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($definicion->listFields()) + count($definicion->badges) + 1 }}" class="px-4 py-12 text-center text-ink-faint">
                                {{ $search === '' ? __('Este catálogo está vacío.') : __('Nada coincide con la búsqueda.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>{{ $filas->links() }}</div>
</div>
