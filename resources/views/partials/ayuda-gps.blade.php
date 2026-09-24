{{-- Instrucciones del catálogo de dispositivos GPS. Ver docs/GPS.md. --}}
@php $api = rtrim((string) config('app.url'), '/').'/api/gps/osmand/'; @endphp
<details class="mt-3 max-w-3xl rounded-xl border border-line bg-panel px-4 py-3 text-sm text-ink-soft">
    <summary class="cursor-pointer font-medium text-ink">{{ __('Cómo conectar un GPS') }}</summary>

    <div class="mt-3 space-y-4">
        <div>
            <p class="font-medium text-ink">{{ __('1. Para probar con un celular') }}</p>
            <ol class="mt-1 list-inside list-decimal space-y-1">
                <li>{{ __('Instale la app gratuita Traccar Client (App Store o Google Play).') }}</li>
                <li>{{ __('Dé de alta aquí un dispositivo con el identificador que muestra la app, la unidad y la marca «App del celular».') }}</li>
                <li>{{ __('En la app, como dirección del servidor escriba:') }}
                    <code class="break-all rounded bg-raised px-1 text-xs">{{ $api }}{{ __('CLAVE') }}</code>
                    {{ __('(la clave GPS_TOKEN se la da el administrador del sistema).') }}</li>
                <li>{{ __('Active el servicio en la app: la unidad aparece en el Mapa de la flota en menos de un minuto.') }}</li>
            </ol>
        </div>

        <div>
            <p class="font-medium text-ink">{{ __('2. Con un equipo GPS instalado en la unidad') }}</p>
            <ol class="mt-1 list-inside list-decimal space-y-1">
                <li>{{ __('El instalador configura el equipo con su chip de datos y lo apunta a :servidor, en el puerto de su marca (vea la línea de arriba).', ['servidor' => (string) config('gps.servidor')]) }}</li>
                <li>{{ __('Dé de alta aquí el equipo con su IMEI (viene en la etiqueta del equipo), la unidad donde quedó instalado y su marca.') }}</li>
                <li>{{ __('Un equipo sin dar de alta se ignora: sus posiciones no se guardan.') }}</li>
            </ol>
        </div>

        <p class="text-xs text-ink-muted">
            {{ __('Recomendado: equipos 4G (las redes 2G y 3G se están apagando en México), con respaldo de batería y entrada de encendido del motor.') }}
        </p>
    </div>
</details>
