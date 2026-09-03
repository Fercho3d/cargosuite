@php use App\Support\Marca; @endphp

<div>
    {{-- Portada --}}
    <section class="mx-auto max-w-6xl px-4 pb-14 pt-14 sm:px-6 sm:pb-20 sm:pt-20">
        <p class="text-xs font-semibold uppercase tracking-[0.25em] text-brand">
            {{ __('Operación, facturación y cobranza') }}
        </p>

        <h1 class="mt-4 max-w-3xl text-3xl font-bold leading-tight tracking-tight text-ink sm:text-5xl">
            {{ __('Todo el expediente, desde que se cotiza hasta que se cobra.') }}
        </h1>

        <p class="mt-5 max-w-2xl text-base leading-relaxed text-ink-muted sm:text-lg">
            {{ __('Un solo sistema para llevar los embarques, emitir la factura, controlar lo que cuesta cada uno y saber cuánto se ganó. Con portal para que tus clientes y proveedores vean lo suyo sin llamarte.') }}
        </p>

        <div class="mt-8 flex flex-wrap items-center gap-3">
            <a href="#demo"
               class="rounded-xl bg-accent-500 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-accent-600">
                {{ __('Solicitar una demostración') }}
            </a>
            <a href="{{ route('login') }}" wire:navigate
               class="rounded-xl border border-line px-5 py-3 text-sm font-semibold text-ink-soft transition hover:bg-raised">
                {{ __('Ya tengo cuenta') }}
            </a>
        </div>
    </section>

    {{-- Lo que resuelve --}}
    <section class="border-y border-line bg-panel">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-14 sm:grid-cols-3 sm:px-6">
            @foreach ([
                [__('Dejar de perseguir el dato'), __('El estado de cada embarque, sus documentos y sus fechas en un solo sitio. Quien pregunta entra y lo ve.')],
                [__('Saber qué deja cada trabajo'), __('La utilidad por expediente, con sus costos y su facturación, sin armarla a mano en una hoja de cálculo.')],
                [__('Cobrar y pagar a tiempo'), __('Facturas emitidas, saldos abiertos y solicitudes de pago agrupadas por proveedor y divisa.')],
            ] as [$titulo, $texto])
                <div>
                    <h3 class="text-base font-semibold text-ink">{{ $titulo }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ $texto }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Módulos --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <h2 class="text-2xl font-bold tracking-tight text-ink sm:text-3xl">{{ __('Qué incluye') }}</h2>

        <div class="mt-8 grid gap-x-10 gap-y-8 sm:grid-cols-2">
            @foreach ([
                [__('Operación'), __('Expedientes con su ruta, sus unidades y sus documentos. Una lista de hitos que tú defines, con el avance a la vista y el historial de quién cambió qué y cuándo.')],
                [__('Facturación'), __('La factura y los costos se proponen desde el expediente y se revisan antes de escribir nada. CFDI 4.0 con timbrado y cancelación, o sin timbrado si no facturas en México.')],
                [__('Costos y proveedores'), __('Cada costo con su proveedor, su divisa y su tipo de cambio. Solicitudes de pago agrupadas, con su documento impreso y su control de saldo.')],
                [__('Reportes'), __('Utilidad por expediente, reportes por cliente y por proveedor, y exportación a Excel de lo que estés viendo, con el filtro aplicado.')],
                [__('Portal de clientes y proveedores'), __('El cliente ve sus embarques y sus facturas. El proveedor sube su factura en PDF y XML y pide su pago, sin correos de ida y vuelta.')],
                [__('Cuentas y seguridad'), __('Permisos por rol, verificación en dos pasos, y cada quien entra solo a lo suyo. Español e inglés, tema claro y oscuro.')],
            ] as [$titulo, $texto])
                <div class="border-t border-line pt-5">
                    <h3 class="text-base font-semibold text-ink">{{ $titulo }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ $texto }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- El diferenciador de verdad --}}
    <section class="border-y border-line bg-panel">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
            <h2 class="text-2xl font-bold tracking-tight text-ink sm:text-3xl">
                {{ __('Se adapta a tu negocio sin programar') }}
            </h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-ink-muted">
                {{ __('No todos mueven contenedores. Los hitos del expediente, los campos que se piden, los catálogos que se ven y hasta cómo se llama cada cosa se capturan desde el propio sistema.') }}
            </p>

            <ul class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    [__('Tus hitos'), __('Recolección y zarpe, o recepción, diagnóstico y entrega. Los defines tú.')],
                    [__('Tus campos'), __('Agrega los que te falten —número de serie, horas de uso— y apaga los que te sobren.')],
                    [__('Tu vocabulario'), __('Que la pantalla diga «orden de servicio» donde otro dice «booking».')],
                    [__('Tu marca'), __('Nombre, logotipo y color de tu empresa en todo el sistema y en los documentos.')],
                ] as [$titulo, $texto])
                    <li class="rounded-xl border border-line bg-surface p-4">
                        <p class="text-sm font-semibold text-ink">{{ $titulo }}</p>
                        <p class="mt-1.5 text-xs leading-relaxed text-ink-muted">{{ $texto }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Solicitud --}}
    <section id="demo" class="mx-auto max-w-3xl scroll-mt-20 px-4 py-16 sm:px-6">
        <h2 class="text-2xl font-bold tracking-tight text-ink sm:text-3xl">{{ __('Solicitar una demostración') }}</h2>
        <p class="mt-3 text-sm leading-relaxed text-ink-muted">
            {{ __('Déjanos tus datos y te enseñamos el sistema funcionando con una operación de ejemplo. Sin instalar nada.') }}
        </p>

        @if ($enviada)
            <div class="mt-8 rounded-xl border border-line bg-panel p-6 text-center">
                <p class="text-base font-semibold text-ink">{{ __('Solicitud recibida.') }}</p>
                <p class="mt-2 text-sm text-ink-muted">{{ __('Te contactamos en breve para agendar la demostración.') }}</p>
            </div>
        @else
            <form wire:submit="solicitar" class="mt-8 grid gap-4 sm:grid-cols-2">
                @foreach ([
                    ['nombre', __('Nombre'), 'text', true],
                    ['empresa', __('Empresa'), 'text', false],
                    ['correo', __('Correo'), 'email', true],
                    ['telefono', __('Teléfono'), 'text', false],
                ] as [$campo, $etiqueta, $tipo, $obligatorio])
                    <label class="block">
                        <span class="field-label">
                            {{ $etiqueta }} @if ($obligatorio) <span class="text-brand">*</span> @endif
                        </span>
                        <input type="{{ $tipo }}" wire:model="{{ $campo }}" value="{{ $$campo }}"
                               class="field-input mt-1.5" @required($obligatorio)>
                        @error($campo) <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                    </label>
                @endforeach

                <label class="block sm:col-span-2">
                    <span class="field-label">{{ __('¿Qué te gustaría ver?') }}</span>
                    <textarea wire:model="mensaje" rows="3" class="field-input mt-1.5">{{ $mensaje }}</textarea>
                    @error('mensaje') <span class="mt-1 block text-xs text-brand">{{ $message }}</span> @enderror
                </label>

                {{-- Trampa para robots: nadie la ve, así que si viene llena es
                     que no la llenó una persona. `aria-hidden` y `tabindex` la
                     esconden también de un lector de pantalla. --}}
                <div class="hidden" aria-hidden="true">
                    <label>{{ __('Sitio web') }}
                        <input type="text" wire:model="sitioWeb" tabindex="-1" autocomplete="off">
                    </label>
                </div>

                <div class="sm:col-span-2">
                    <x-submit-button>{{ __('Enviar solicitud') }}</x-submit-button>
                </div>
            </form>
        @endif
    </section>
</div>
