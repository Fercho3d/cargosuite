{{--
    Botón de envío con indicador de carga. No se deshabilita al pulsarlo (algunos
    navegadores cancelan el envío si el botón se deshabilita en el mismo evento);
    se bloquean los clics y se muestra el giro.
--}}
<button type="submit"
        x-data="{ enviando: false }"
        x-on:click="if ($el.form === null || $el.form.checkValidity()) enviando = true"
        :class="enviando && 'pointer-events-none opacity-80'"
        :aria-busy="enviando"
        {{ $attributes->merge(['class' => 'btn-accent']) }}>
    <x-spinner x-show="enviando" x-cloak class="h-4 w-4" />
    <span>{{ $slot }}</span>
</button>
