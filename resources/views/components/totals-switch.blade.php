{{-- Switch «mostrar sumatoria»: enciende el pie de totales y se recuerda en
     cookie. Todo el control (perilla + texto) es un solo botón, y Alpine
     mueve la perilla al instante mientras el servidor recalcula el pie. --}}
<button type="button" role="switch" x-data
        x-on:click="$wire.$set('showTotals', ! $wire.showTotals)"
        :aria-checked="$wire.showTotals ? 'true' : 'false'"
        class="group inline-flex cursor-pointer select-none items-center gap-2 text-xs text-ink-soft focus:outline-none">
    <span class="relative inline-flex h-5 w-9 flex-shrink-0 items-center rounded-full transition group-focus-visible:ring-2 group-focus-visible:ring-accent-500/50"
          :class="$wire.showTotals ? 'bg-accent-500' : 'bg-ink-faint'">
        <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition"
              :class="$wire.showTotals ? 'translate-x-4' : 'translate-x-0.5'"></span>
    </span>
    {{ __('Mostrar sumatoria') }}
</button>
