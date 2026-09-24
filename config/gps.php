<?php

return [

    /*
     * Clave con la que Traccar (o la app del celular) se identifica al mandar
     * posiciones. Vacía = la API está cerrada: nadie puede escribir posiciones.
     */
    'token' => env('GPS_TOKEN', ''),

    /*
     * Dirección del servidor Traccar al que se configuran los equipos (cada
     * protocolo en su puerto). Va sin proxy de Cloudflare: los GPS hablan TCP.
     */
    'servidor' => env('GPS_SERVIDOR', 'gps.ejemplo.com'),

    /* Minutos sin reportar a partir de los cuales una unidad sale «sin señal». */
    'sin_senal_minutos' => (int) env('GPS_SIN_SENAL_MINUTOS', 15),

    /* Más lento que esto, en km/h, la unidad se considera detenida. */
    'velocidad_detenida' => 5,

    /*
     * Servicio que calcula la ruta por carretera de cada viaje (ver docs/GPS.md):
     * - `ors`: OpenRouteService, perfil de camión de carga. Pide ORS_API_KEY
     *   (gratis con límite diario en openrouteservice.org).
     * - `osrm`: OSRM. El servidor público es solo para pruebas; en producción
     *   va uno propio (OSRM_URL).
     * - `ninguno`: línea recta entre las paradas, marcada como aproximada.
     * Sin proveedor elegido: `ors` si hay clave, si no `osrm`.
     */
    'rutas' => [
        'proveedor' => env('RUTAS_PROVEEDOR') ?: (env('ORS_API_KEY') ? 'ors' : 'osrm'),
        'ors_key' => env('ORS_API_KEY', ''),
        'osrm_url' => env('OSRM_URL', 'https://router.project-osrm.org'),
    ],

    /* Días que se guarda el historial de posiciones. */
    'retencion_dias' => (int) env('GPS_RETENCION_DIAS', 90),

];
