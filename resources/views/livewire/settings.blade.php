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
            <legend class="px-1 text-sm font-semibold text-ink">{{ __('Flota y personal') }}</legend>

            <label class="mt-3 flex cursor-pointer gap-3">
                <input type="checkbox" wire:model="taller" class="mt-0.5 h-4 w-4 shrink-0 rounded border-line">
                <span class="min-w-0">
                    <span class="block text-sm font-medium text-ink">{{ __('Llevar el taller y el almacén') }}</span>
                    <span class="mt-1 block text-xs leading-relaxed text-ink-muted">
                        {{ __('Órdenes de mantenimiento por unidad —preventivo y correctivo, propio o externo— con las refacciones que se le ponen, descontadas de un almacén con mínimos y kárdex. Apágalo si todo el mantenimiento se manda fuera y no llevas refacciones.') }}
                    </span>
                </span>
            </label>

            <label class="mt-3 flex cursor-pointer gap-3">
                <input type="checkbox" wire:model="nomina" class="mt-0.5 h-4 w-4 shrink-0 rounded border-line">
                <span class="min-w-0">
                    <span class="block text-sm font-medium text-ink">{{ __('Llevar la nómina aquí') }}</span>
                    <span class="mt-1 block text-xs leading-relaxed text-ink-muted">
                        {{ __('Reúne sueldos, viajes, bonos y descuentos de la plantilla y saca el neto para dispersar. No calcula IMSS ni ISR ni timbra el CFDI de nómina: eso se hace con el archivo que se exporta. Apágalo si ya llevas la nómina en otro sistema.') }}
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

    @if ($demo)
        {{-- Fuera del formulario: esto no se guarda, se ejecuta. --}}
        <div class="mt-6 rounded-2xl border border-line bg-panel p-5">
            <p class="text-sm font-semibold text-ink">{{ __('Datos de demostración') }}</p>
            <p class="mt-1 text-xs leading-relaxed text-ink-muted">
                {{ __('Llena el sistema con la operación de un negocio completo —clientes, rutas, viajes, facturas, costos y nómina— y deja la pantalla acomodada a esa forma de transportar. Sirve para enseñarlo con los datos del negocio que se tiene enfrente.') }}
            </p>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ([
                    ['maritimo', __('Agente de carga marítima'), __('Puertos, buques, navieras y agentes aduanales. Fletes en dólares por contenedor, tránsitos de semanas.')],
                    ['terrestre', __('Autotransporte de carga (México)'), __('Rutas reales entre patios y ciudades con sus kilómetros, operadores y tractores propios. Fletes en pesos por kilómetro con IVA y retención del 4 %, diésel y casetas de cada viaje, liquidaciones y nómina.')],
                ] as [$clave, $titulo, $detalle])
                    <div class="flex flex-col rounded-xl border {{ $vertical === $clave ? 'border-accent-500' : 'border-line' }} p-4">
                        <p class="text-sm font-medium text-ink">
                            {{ $titulo }}
                            @if ($vertical === $clave)
                                <span class="badge-ok ml-1 rounded px-1.5 py-0.5 text-[10px]">{{ __('En uso') }}</span>
                            @endif
                        </p>
                        <p class="mt-1 flex-1 text-xs leading-relaxed text-ink-muted">{{ $detalle }}</p>

                        <button type="button" wire:click="rellenar('{{ $clave }}')"
                                wire:confirm="{{ __('Esto BORRA todo lo que hay ahora —expedientes, facturas, cobros, usuarios— y lo reemplaza con datos de ejemplo. No se puede deshacer. ¿Seguir?') }}"
                                wire:loading.attr="disabled" wire:target="rellenar"
                                class="btn-ghost mt-3 justify-center !py-1.5 text-xs">
                            <x-spinner wire:loading wire:target="rellenar('{{ $clave }}')" class="h-3.5 w-3.5" />
                            {{ __('Rellenar con estos datos') }}
                        </button>
                    </div>
                @endforeach
            </div>

            <p class="mt-4 text-xs leading-relaxed text-brand">
                {{ __('⚠️ Borra la base y la vuelve a llenar, cuentas de usuario incluidas: al terminar hay que volver a entrar. Este apartado solo existe en una instalación de demostración (MARCA_DEMO).') }}
            </p>
        </div>
    @endif
</div>
