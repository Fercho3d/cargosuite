@props(['wrapper' => ''])

{{--
    Campo de contraseña con el ojo para mostrarla.

    Teclear una contraseña larga a ciegas es la primera causa de «no me deja
    entrar». El interruptor vive solo en el navegador: cambia el `type` del
    campo y nada más; el valor nunca se copia a otro lado.

    Se apaga solo al enviar el formulario, para no dejar la contraseña a la
    vista si el navegador se queda en la pantalla.
--}}
<div class="relative {{ $wrapper }}" x-data="{ visible: false }" x-on:submit.window="visible = false">
    <input {{ $attributes->merge(['class' => 'field-input pr-11']) }}
           x-bind:type="visible ? 'text' : 'password'"
           type="password">

    <button type="button"
            x-on:click="visible = !visible"
            x-bind:aria-label="visible ? '{{ __('Ocultar la contraseña') }}' : '{{ __('Mostrar la contraseña') }}'"
            x-bind:aria-pressed="visible"
            tabindex="-1"
            class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-ink-faint transition hover:text-ink-soft">
        {{-- Ojo abierto: la contraseña está oculta, tócalo para verla. --}}
        <svg x-show="! visible" class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24"
             stroke="currentColor" stroke-width="1.7" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/>
            <circle cx="12" cy="12" r="3"/>
        </svg>
        {{-- Ojo tachado: se está viendo. --}}
        <svg x-show="visible" x-cloak class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24"
             stroke="currentColor" stroke-width="1.7" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 5.2A9.6 9.6 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.2 6.2A17 17 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 3.4-.6"/>
        </svg>
    </button>
</div>
