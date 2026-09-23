<?php

/*
 * Los datos de ejemplo.
 *
 * ⚠️ **Estos valores tienen que vivir en un archivo de configuración y no
 * leerse con `env()` donde se usan.** Con la configuración cacheada —que es
 * como corre producción— Laravel ni siquiera abre el `.env`, así que un
 * `env('DEMO_PASSWORD')` a la hora de sembrar devuelve el valor de fábrica y
 * las cuentas se crean con OTRA contraseña. Pasó: rellenar la demostración
 * desde la pantalla dejó a todo el mundo fuera.
 *
 * Los archivos de `config/` sí se evalúan al construir el caché, o sea que aquí
 * el `.env` se lee una vez y queda dentro.
 */
return [

    /*
     * Qué vertical se siembra cuando no se elige explícitamente.
     * Hay: carga (agente de carga), camiones (autotransporte), servicios (taller).
     */
    'perfil' => env('DEMO_PERFIL', 'carga'),

    /* La contraseña de las cinco cuentas de ejemplo. */
    'password' => env('DEMO_PASSWORD', 'demo1234'),

    /*
     * Sí, borra una base que ya tiene operación y vuélvela a llenar.
     *
     * Es la salvaguarda del sembrador por línea de comandos; el botón de
     * Ajustes no la usa, pide sobrescribir de forma explícita.
     */
    'forzar' => filter_var(env('DEMO_SEED_FORCE', false), FILTER_VALIDATE_BOOL),

    /*
     * La cuenta de administración que se crea en una instalación limpia, para
     * poder entrar la primera vez. Solo para desarrollo.
     */
    'admin' => [
        'usuario' => env('DEV_ADMIN_USER', ''),
        'password' => env('DEV_ADMIN_PASSWORD', ''),
    ],

];
