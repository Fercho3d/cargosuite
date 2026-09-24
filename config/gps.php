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

    /* Días que se guarda el historial de posiciones. */
    'retencion_dias' => (int) env('GPS_RETENCION_DIAS', 90),

];
