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
            <a href="{{ route('login') }}" wire:navigate
               class="rounded-xl bg-accent-500 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-accent-600">
                {{ __('Iniciar sesión') }}
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
</div>
