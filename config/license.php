<?php

/*
 * Licencia del software.
 *
 * El candado que da control sobre el uso: la aplicación solo se abre con una
 * licencia FIRMADA con la llave privada del proveedor y no vencida. Aunque se
 * lea todo el código fuente, sin esa llave privada NO se puede fabricar una
 * licencia válida.
 *
 * ⚠️ La llave PÚBLICA vive en la configuración (no se lee con `env()` fuera de
 * aquí) para que sobreviva a `config:cache`, que es como corre producción. La
 * llave PRIVADA jamás se sube al servidor: solo se usa al emitir licencias con
 * `php artisan license:issue` en el equipo del proveedor.
 *
 * Mientras no haya llave pública configurada, el candado queda inactivo: así el
 * código se puede desplegar primero y armar después (poniendo la llave pública
 * y una licencia), sin dejar a nadie fuera por accidente.
 */
return [

    // Se puede apagar en desarrollo. En producción va en true.
    'enforce' => (bool) env('LICENSE_ENFORCE', true),

    // Llave pública Ed25519 en base64. La imprime `php artisan license:keys`.
    'public_key' => env('LICENSE_PUBLIC_KEY', ''),

    // Token de licencia por omisión, si no hay uno activado desde la pantalla.
    'token' => env('LICENSE_KEY', ''),

    // Llave privada de firma: SOLO en el equipo del proveedor para emitir.
    // NUNCA debe existir en el .env de producción.
    'signing_key' => env('LICENSE_SIGNING_KEY', ''),

];
