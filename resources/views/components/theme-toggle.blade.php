{{--
    Selector de tema (claro / oscuro / sistema). El valor se guarda en las
    preferencias del usuario y, sin sesión, en una cookie.
--}}
<div x-data="themeSwitcher"
     class="inline-flex items-center gap-0.5 rounded-full border border-line bg-panel p-0.5"
     role="radiogroup"
     aria-label="Tema de la interfaz">
    <template x-for="opcion in opciones" :key="opcion.valor">
        <button type="button"
                role="radio"
                :aria-checked="tema === opcion.valor"
                :title="opcion.etiqueta"
                :aria-label="'Tema ' + opcion.etiqueta"
                x-on:click="seleccionar(opcion.valor)"
                class="relative flex h-7 w-7 items-center justify-center rounded-full transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-500/50"
                :class="tema === opcion.valor
                    ? 'bg-raised text-brand shadow-sm'
                    : 'text-ink-faint hover:text-ink-soft'">
            {{-- Claro --}}
            <svg x-show="opcion.valor === 'light'" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                 stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <circle cx="12" cy="12" r="4"/>
                <path stroke-linecap="round" d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4l1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>
            </svg>
            {{-- Oscuro --}}
            <svg x-show="opcion.valor === 'dark'" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                 stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>
            </svg>
            {{-- Sistema --}}
            <svg x-show="opcion.valor === 'system'" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                 stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <rect x="3" y="4" width="18" height="12" rx="2"/>
                <path stroke-linecap="round" d="M9 20h6m-3-4v4"/>
            </svg>
        </button>
    </template>
</div>
