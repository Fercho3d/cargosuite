<?php

/**
 * Timbrado CFDI 4.0 ante el PAC.
 *
 * Los valores viven en el `.env` de cada servidor, igual que en Yii2 vivían en
 * `config/secrets.php` (fuera del repositorio). Aquí no hay ningún secreto.
 */
return [

    /*
     * Interruptor de producción.
     *
     * Debe ser `true` SOLO en el servidor de producción. Sin él se timbra contra
     * el ambiente de pruebas del PAC, que no genera documentos fiscales.
     *
     * En Yii2 esto se adivinaba por el nombre del servidor, y el propio comentario
     * del código advertía que era frágil: al entrar por «localhost» se caía al PAC
     * de pruebas sin avisar. Aquí es explícito y su valor por omisión es el seguro.
     */
    'produccion' => env('TIMBRADO_PRODUCCION', false),

    /*
     * OJO: son DOS RFC distintos y no deben mezclarse.
     *
     * 1. `rfc_cuenta` identifica la CUENTA contratada con el PAC y viaja junto al
     *    usuario y la contraseña.
     * 2. El RFC del EMISOR del documento sale de la compañía de cada transacción
     *    (multiemisor) y se resuelve en tiempo de timbrado.
     *
     * Históricamente la cuenta es una y el emisor del XML otro, y así funciona.
     */
    /**
     * ¿Esta instalación factura con CFDI?
     *
     * El timbrado es una obligación **mexicana**, y hasta aquí era el único
     * camino a la facturación: el sistema no se podía instalar en un negocio
     * que no factura al SAT sin que le sobraran botones y campos.
     *
     * En falso desaparecen el timbrado, la cancelación, el aviso de «facturas
     * sin timbrar» y los campos fiscales del cliente. Las facturas se siguen
     * emitiendo, imprimiendo y cobrando igual.
     *
     * Los catálogos del SAT se ocultan aparte, con `MARCA_CATALOGOS`.
     */
    'habilitado' => env('TIMBRADO_HABILITADO', true),

    'rfc_cuenta' => env('TIMBRADO_RFC'),

    /**
     * Nombre del emisor cuando la transacción no trae compañía (facturas
     * anteriores al catálogo de compañías). Estaba escrito dentro del código,
     * con la razón social del primer cliente.
     */
    'emisor_nombre' => env('TIMBRADO_EMISOR_NOMBRE', ''),
    'usuario' => env('TIMBRADO_USER'),
    'password' => env('TIMBRADO_PASSWORD'),

    'endpoints' => [
        'pruebas' => env('TIMBRADO_URL_PRUEBAS', 'https://t1demo.facturacionmoderna.com/timbrado/wsdl'),
        'produccion' => env('TIMBRADO_URL_PRODUCCION', 'https://t1.dinvbox.mx/timbrado/wsdl'),

        /*
         * Un CFDI se cancela ante el MISMO PAC que lo timbró; si se deja vacío se
         * usa el endpoint de timbrado.
         */
        'cancelacion' => env('TIMBRADO_URL_CANCELACION'),
    ],

    /*
     * Credenciales públicas del ambiente de pruebas de Facturación Moderna. No
     * son secretas —vienen en su documentación— y sirven para que el sistema
     * funcione en local sin configurar nada.
     */
    'demo' => [
        'rfc_cuenta' => 'TCM970625MB1',
        'usuario' => 'UsuarioPruebasWS',
        'password' => 'b9ec2afa3361a59af4b4d102d3f704eabdf097d4',
    ],

    /*
     * Código postal del lugar de expedición cuando la compañía emisora no lo
     * tiene capturado. Es el valor histórico del sistema.
     */
    'lugar_expedicion_por_omision' => '44648',

    /*
     * Verificar el certificado del PAC. Se deja encendido: el sistema original lo
     * desactivaba, y eso deja la puerta abierta a un intermediario en el envío de
     * documentos fiscales. Solo apagarlo si la cadena del PAC da problemas.
     */
    'verificar_tls' => env('TIMBRADO_VERIFICAR_TLS', true),
];
