@php
    $campos = $this->fields();
    $esAdmin = auth()->user()?->isAdmin() ?? false;

    // En el listado solo las columnas que sirven para reconocer al tercero.
    $enLista = array_intersect_key($campos, array_flip(['fullName', 'rfc', 'email', 'city', 'phone']));
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
                   class="field-input py-1.5 text-sm" placeholder="{{ __('Buscar por nombre, RFC o ciudad…') }}">
            @if ($esAdmin)
                <button type="button" wire:click="create" class="btn-accent !px-3 !py-1.5 text-xs">{{ __('Agregar') }}</button>
            @endif
        </div>
    </header>

    {{-- Formulario --}}
    @if ($editing !== null)
        <form wire:submit="save" class="card space-y-4 p-5">
            <p class="text-sm font-medium text-ink">
                {{ $editing === 0 ? __('Nuevo registro') : __('Editar registro') }}
            </p>

            @include('partials.validation-errors')

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($campos as $campo => [$etiqueta, $tipo, $reglas])
                    <label class="block">
                        <span class="field-label">{{ $etiqueta }}</span>

                        @if ($tipo === 'select')
                            <select wire:model="form.{{ $campo }}" class="field-input mt-1.5">
                                <option value="">{{ __('Sin especificar') }}</option>
                                @foreach ($this->optionsFor($campo) as $id => $nombre)
                                    <option value="{{ $id }}" @selected((string) $id === (string) ($form[$campo] ?? ''))>{{ $nombre }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="text" wire:model="form.{{ $campo }}" value="{{ $form[$campo] ?? '' }}" class="field-input mt-1.5">
                        @endif

                        @error('form.'.$campo) <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                    </label>
                @endforeach
            </div>

            @if ($this->isClient())
                {{-- Qué papeles se le piden a este cliente en cada booking --}}
                <div class="rounded-xl border border-line bg-raised/40 p-4">
                    <p class="text-sm font-medium text-ink">{{ __('Documentos que se le piden') }}</p>
                    <p class="mt-0.5 text-xs text-ink-faint">
                        {{ __('Son los campos donde operación sube papeles en cada booking de este cliente. Si no marcas ninguno, su booking no ofrecerá dónde subirlos.') }}
                    </p>

                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($this->documentCatalog() as $id => $etiqueta)
                            <label class="flex items-center gap-2 text-sm text-ink-soft">
                                <input type="checkbox" wire:model="documentFields" value="{{ $id }}"
                                       class="h-4 w-4 rounded border-line bg-panel text-accent-500 focus:ring-accent-500">
                                {{ $etiqueta }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            <p class="text-xs text-ink-faint">
                {{ __('Los accesos al portal se administran en la pantalla de usuarios, no aquí: así las contraseñas se cambian en un solo lugar.') }}
            </p>

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
                <x-spinner class="h-4 w-4 text-brand" /> Actualizando…
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
                                    {{ $fila->{$campo} ?: '—' }}
                                </td>
                            @endforeach
                            @if ($esAdmin)
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    <button type="button" wire:click="edit({{ $fila->{$this->key()} }})"
                                            class="text-xs text-brand hover:underline">{{ __('Editar') }}</button>
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
