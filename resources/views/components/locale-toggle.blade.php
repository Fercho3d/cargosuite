{{--
    Selector de idioma (español / inglés), hermano del selector de tema: mismo
    tamaño, misma píldora y la misma manera de guardarse.
--}}
<div x-data="localeSwitcher"
     class="inline-flex items-center gap-0.5 rounded-full border border-line bg-panel p-0.5"
     role="radiogroup"
     aria-label="{{ __('Idioma de la interfaz') }}">
    <template x-for="opcion in opciones" :key="opcion.valor">
        <button type="button"
                role="radio"
                :aria-checked="idioma === opcion.valor"
                :title="opcion.etiqueta"
                :aria-label="opcion.etiqueta"
                x-on:click="seleccionar(opcion.valor)"
                class="flex h-7 w-7 items-center justify-center rounded-full text-[11px] font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-500/50"
                :class="idioma === opcion.valor
                    ? 'bg-raised text-brand shadow-sm'
                    : 'text-ink-faint hover:text-ink-soft'"
                x-text="opcion.corto">
        </button>
    </template>
</div>
