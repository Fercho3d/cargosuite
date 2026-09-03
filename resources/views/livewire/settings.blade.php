<div class="mx-auto max-w-3xl space-y-5">
    <header>
        <h1 class="text-lg font-semibold text-ink">{{ __('Ajustes') }}</h1>
        <p class="mt-0.5 text-sm text-ink-muted">
            {{ __('Cómo se comporta esta instalación. Afecta a todos los usuarios.') }}
        </p>
    </header>

    @include('partials.validation-errors')

    @if (session('status'))
        <div class="alert-ok rounded-lg px-3 py-2 text-sm">{{ session('status') }}</div>
    @endif

    <form wire:submit="guardar" class="space-y-5">
        <fieldset class="rounded-2xl border border-line bg-panel p-5">
            <legend class="px-1 text-sm font-semibold text-ink">{{ __('Forma de transporte') }}</legend>
            <p class="mt-1 text-sm text-ink-muted">
                {{ __('Decide qué campos y qué catálogos se enseñan. Si la empresa hace las dos cosas, marca las dos.') }}
            </p>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ([
                    ['maritimo', __('Marítima'), __('Buque en el expediente y catálogo de buques.')],
                    ['terrestre', __('Terrestre'), __('Operador, tractor y caja; catálogos de operadores y unidades, y liquidaciones.')],
                ] as [$valor, $etiqueta, $detalle])
                    <label class="flex cursor-pointer gap-3 rounded-xl border border-line bg-surface p-4 transition hover:bg-raised">
                        <input type="checkbox" wire:model="modalidades" value="{{ $valor }}"
                               class="mt-0.5 h-4 w-4 shrink-0 rounded border-line">
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-ink">{{ $etiqueta }}</span>
                            <span class="mt-1 block text-xs leading-relaxed text-ink-muted">{{ $detalle }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <fieldset class="rounded-2xl border border-line bg-panel p-5">
            <legend class="px-1 text-sm font-semibold text-ink">{{ __('Facturación') }}</legend>

            <label class="mt-3 flex cursor-pointer gap-3">
                <input type="checkbox" wire:model="timbrado" class="mt-0.5 h-4 w-4 shrink-0 rounded border-line">
                <span class="min-w-0">
                    <span class="block text-sm font-medium text-ink">{{ __('Facturar con CFDI (México)') }}</span>
                    <span class="mt-1 block text-xs leading-relaxed text-ink-muted">
                        {{ __('Apagado, las facturas se siguen emitiendo, imprimiendo y cobrando: lo que desaparece es el timbrado y los campos del SAT.') }}
                    </span>
                </span>
            </label>
        </fieldset>

        <fieldset class="rounded-2xl border border-line bg-panel p-5">
            <legend class="px-1 text-sm font-semibold text-ink">{{ __('Vocabulario e idioma') }}</legend>

            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="field-label">{{ __('Vocabulario del negocio') }}</span>
                    <select wire:model="vocabulario" class="field-input mt-1.5">
                        <option value="">{{ __('El de origen (carga marítima)') }}</option>
                        @foreach ($this->vocabularios() as $v)
                            <option value="{{ $v }}" @selected($v === $vocabulario)>{{ $v }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="field-label">{{ __('Idioma de los documentos') }}</span>
                    <select wire:model="idiomaDocumentos" class="field-input mt-1.5">
                        <option value="es" @selected($idiomaDocumentos === 'es')>{{ __('Español') }}</option>
                        <option value="en" @selected($idiomaDocumentos === 'en')>{{ __('Inglés') }}</option>
                    </select>
                    <span class="mt-1 block text-xs text-ink-faint">
                        {{ __('Los PDF y correos que salen al cliente, aparte del idioma de la pantalla.') }}
                    </span>
                </label>
            </div>
        </fieldset>

        <x-submit-button>{{ __('Guardar ajustes') }}</x-submit-button>
    </form>
</div>
