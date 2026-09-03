{{-- Iconos del menú lateral. `icon` llega desde el arreglo de navegación. --}}
@php $trazo = 'h-4.5 w-4.5 shrink-0'; @endphp
<svg class="{{ $trazo }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
    @switch($icon)
        @case('panel')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 13h6V4H4v9zm0 7h6v-5H4v5zm10 0h6v-9h-6v9zm0-16v5h6V4h-6z"/>
            @break
        @case('factura')
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12v18l-3-2-3 2-3-2-3 2V3zm3 5h6M9 12h6"/>
            @break
        @case('costo')
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m4-14H9.5a2.5 2.5 0 0 0 0 5h5a2.5 2.5 0 0 1 0 5H7"/>
            @break
        @case('transaccion')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h13l-3-3m3 13H4l3 3"/>
            @break
        @case('catalogo')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
            @break
        @case('operacion')
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16V8l4-3 4 3v8m0-6h6l4 3v3m-14 0a2 2 0 1 0 4 0 2 2 0 0 0-4 0zm10 0a2 2 0 1 0 4 0 2 2 0 0 0-4 0z"/>
            @break
        @case('usuarios')
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 19v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M9.5 7.5a3 3 0 1 0 0 .01M21 19v-1a4 4 0 0 0-3-3.87M16 4.13a4 4 0 0 1 0 7.75"/>
            @break
        @case('reporte')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 19h16M7 16V9m5 7V5m5 11v-4"/>
            @break
        @case('banco')
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10l9-5 9 5M5 10v8m6-8v8m8-8v8M3 20h18"/>
            @break
        @case('ajustes')
            <circle cx="12" cy="12" r="3"/>
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            @break
        @default
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l9 5v8l-9 5-9-5V8l9-5z"/>
    @endswitch
</svg>
