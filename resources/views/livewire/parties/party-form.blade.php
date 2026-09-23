@php
    $money = fn ($v) => number_format((float) $v, 2);
    $servicios = $this->services();
@endphp

<div class="mx-auto max-w-5xl space-y-4">

    {{-- Regreso a la lista, con su búsqueda y su página --}}
    <a href="{{ $volver }}" wire:navigate
       class="inline-flex items-center gap-1.5 text-sm text-ink-muted transition hover:text-ink">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        {{ $this->title() }}
    </a>

    <form wire:submit="save" class="card space-y-4 p-5 sm:p-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-faint">
                {{ $this->isClient() ? __('Cliente') : __('Proveedor') }}
            </p>
            <h2 class="mt-0.5 text-xl font-semibold text-ink">{{ $titulo }}</h2>
        </div>

        @include('partials.validation-errors')

        @if ($this->requiresCfdi())
            <p class="text-xs text-ink-faint">
                {{ __('El RFC, la dirección, el código postal y el régimen fiscal son obligatorios: viajan al CFDI 4.0 y el SAT los valida.') }}
            </p>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->campos as $campo => [$etiqueta, $tipo, $reglas])
                @php $obligatorio = in_array('required', $reglas, true); @endphp
                <label class="block {{ in_array($tipo, ['checkbox', 'textarea'], true) ? 'sm:col-span-2 lg:col-span-3' : '' }}">
                    @if ($tipo === 'checkbox')
                        <span class="flex items-center gap-2 text-sm text-ink-soft">
                            <input type="checkbox" wire:model="form.{{ $campo }}" @checked($form[$campo] ?? false)
                                   class="h-4 w-4 rounded border-line bg-panel text-accent-500 focus:ring-accent-500">
                            {{ $etiqueta }}
                        </span>
                    @else
                        <span class="field-label">{{ $etiqueta }}@if ($obligatorio) <span class="text-brand">*</span>@endif</span>

                        @if ($tipo === 'select')
                            <select wire:model="form.{{ $campo }}" class="field-input mt-1.5">
                                <option value="">{{ __('Sin especificar') }}</option>
                                @foreach ($this->optionsFor($campo) as $id => $nombre)
                                    <option value="{{ $id }}" @selected((string) $id === (string) ($form[$campo] ?? ''))>{{ $nombre }}</option>
                                @endforeach
                            </select>
                        @elseif ($tipo === 'textarea')
                            <textarea wire:model="form.{{ $campo }}" rows="3" class="field-input mt-1.5">{{ $form[$campo] ?? '' }}</textarea>
                        @else
                            <input type="text" wire:model="form.{{ $campo }}" value="{{ $form[$campo] ?? '' }}"
                                   class="field-input mt-1.5 {{ $campo === 'rfc' ? 'uppercase' : '' }}"
                                   @if ($campo === 'phone') inputmode="tel" placeholder="{{ __('Solo dígitos, sin lada internacional') }}" @endif>
                        @endif
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
                    @foreach ($this->documentCatalog as $id => $etiqueta)
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
            <a href="{{ $volver }}" wire:navigate class="btn-ghost !px-3 !py-1.5 text-xs">{{ __('Cancelar') }}</a>
            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="btn-accent !px-3 !py-1.5 text-xs">
                <x-spinner wire:loading wire:target="save" class="h-3.5 w-3.5" />
                {{ __('Guardar') }}
            </button>
        </div>
    </form>

    {{-- Servicios pactados: se ven aquí y se editan en Servicios y precios --}}
    @if ($partyId !== null)
        <section class="card overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div>
                    <h3 class="text-sm font-semibold text-ink">{{ __('Servicios y precios') }}</h3>
                    <p class="text-xs text-ink-faint">{{ trans_choice(':n servicio|:n servicios', $servicios->count(), ['n' => $servicios->count()]) }}</p>
                </div>
                {{-- Servicios y precios es del super administrador, como en Yii2 --}}
                @if (auth()->user()?->isSuperAdmin())
                    <a href="{{ $this->servicesUrl() }}" wire:navigate class="btn-ghost px-3 py-1.5 text-xs">{{ __('Editar servicios') }}</a>
                @endif
            </header>

            <div class="overflow-x-auto border-t border-line">
                <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase tracking-wide text-ink-muted">
                        <tr>
                            <th class="px-4 py-2.5 text-left font-semibold">{{ __('Servicio') }}</th>
                            <th class="px-4 py-2.5 text-left font-semibold">{{ __('Tipo de cargo') }}</th>
                            <th class="px-4 py-2.5 text-right font-semibold">{{ __('Precio') }}</th>
                            <th class="px-4 py-2.5 text-left font-semibold">{{ __('Estado') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($servicios as $servicio)
                            <tr class="{{ $servicio->active ? '' : 'opacity-60' }}">
                                <td class="max-w-[22rem] truncate px-4 py-2 text-ink" title="{{ $servicio->description }}">{{ $servicio->description ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-ink-muted">{{ $servicio->charge_type_name ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-ink-soft">
                                    {{ (float) $servicio->price === 0.0 ? __('Abierto') : $money($servicio->price).' '.$servicio->currency }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2">
                                    <span class="badge {{ $servicio->active ? 'badge-ok' : 'badge-neutral' }}">
                                        {{ $servicio->active ? __('Activo') : __('Inactivo') }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-10 text-center text-ink-faint">{{ __('No tiene servicios pactados.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
