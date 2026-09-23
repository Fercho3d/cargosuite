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
        @case('taller')
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.7 6.3a4 4 0 0 1-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 0 0 5.4-5.4l-2.3 2.3-2-2 2.3-2.3z"/>
            @break
        @case('almacen')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 8l8-4 8 4v8l-8 4-8-4V8zm0 0l8 4m0 0l8-4m-8 4v8"/>
            @break
        @case('nomina')
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 3h9l3 3v15H6V3zm3 7h6m-6 4h6m-6 4h4"/>
            @break
        @case('ajustes')
            <circle cx="12" cy="12" r="3"/>
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            @break
        @case('dolar')
            <circle cx="12" cy="12" r="8.5"/>
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.8 9.3C14.3 8.5 13.2 8 12 8c-1.5 0-2.7.8-2.7 2s1.2 1.9 2.7 2 2.8.9 2.8 2-1.3 2-2.8 2c-1.2 0-2.3-.5-2.8-1.3M12 6.3v11.4"/>
            @break
        @case('cobrar')
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v10m0 0l-4-4m4 4 4-4M5 16v2a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-2"/>
            @break
        @case('pagar')
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21V11m0 0-4 4m4-4 4 4M5 8V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v2"/>
            @break
        @case('balance')
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M6 21h12M6 6l-3 6a3 3 0 0 0 6 0L6 6zm12 0-3 6a3 3 0 0 0 6 0l-3-6zM6 6h12"/>
            @break
        @case('cambio')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 9a8 8 0 0 1 13.7-4.5L20 7M20 3v4h-4M20 15a8 8 0 0 1-13.7 4.5L4 17M4 21v-4h4"/>
            @break
        @case('recibo')
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 7c0-1.1 3.1-2 7-2s7 .9 7 2-3.1 2-7 2-7-.9-7-2zm0 0v10c0 1.1 3.1 2 7 2s7-.9 7-2V7M5 12c0 1.1 3.1 2 7 2s7-.9 7-2"/>
            @break
        @case('contactos')
            <rect x="3" y="5" width="18" height="14" rx="2"/>
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.5 12a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zm-2.5 4c.3-1.3 1.4-2 2.5-2s2.2.7 2.5 2M14 10h4M14 14h3"/>
            @break
        @case('servicio')
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4h7l9 9-7 7-9-9V4z"/>
            <circle cx="7.5" cy="7.5" r="1.2"/>
            @break
        @case('importacion')
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0-4-4m4 4 4-4M4 17v2a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-2"/>
            @break
        @case('exportacion')
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15V4m0 0L8 8m4-4 4 4M4 17v2a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-2"/>
            @break
        @case('nuevo')
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v8m-4-4h8M4 12a8 8 0 1 0 16 0 8 8 0 0 0-16 0z"/>
            @break
        @case('continuidad')
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 21V4m0 1.5 2-.8a4 4 0 0 1 3 0l2 .8a4 4 0 0 0 3 0l3-1.2v8l-3 1.2a4 4 0 0 1-3 0l-2-.8a4 4 0 0 0-3 0L5 13"/>
            @break
        @default
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l9 5v8l-9 5-9-5V8l9-5z"/>
    @endswitch
</svg>
