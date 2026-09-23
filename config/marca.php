<?php

/**
 * Marca del sistema: todo lo que cambia al vendérselo a otra empresa.
 *
 * La regla es que ningún nombre, color, logotipo ni dirección de correo viva
 * dentro de una vista o de un controlador. Aquí están todos, y cada uno se
 * puede cambiar por `.env` sin tocar una línea de código ni volver a compilar
 * los estilos (los colores se aplican en caliente como variables CSS; ver
 * `resources/views/partials/marca-colores.blade.php`).
 *
 * Instalar el sistema para un cliente nuevo = copiar el bloque MARCA_* del
 * `.env.example`, cambiar los valores y poner sus imágenes en `public/marca/`.
 * El procedimiento completo está en `docs/MARCA-BLANCA.md`.
 */
return [

    /*
    |---------------------------------------------------------------------
    | Identidad
    |---------------------------------------------------------------------
    */

    /**
     * Nombre del producto. Sale en el título de cada pantalla, en el pie del
     * menú y en los correos. Por omisión toma `APP_NAME` para no tener que
     * repetirlo, pero se puede separar (p. ej. el sistema se llama de una
     * forma y el que lo compró le pone otra en su instalación).
     */
    'nombre' => env('MARCA_NOMBRE') ?: env('APP_NAME', 'CargoSuite'),

    /**
     * Etiqueta corta que acompaña al logotipo en la barra del sistema interno.
     * Vacía = no se pinta nada.
     */
    'etiqueta' => env('MARCA_ETIQUETA', 'Cargo'),

    /** Lema bajo el logotipo de la pantalla de acceso. Vacío = no se pinta. */
    'lema' => env('MARCA_LEMA', 'Freight & Logistics Operations'),

    /** Leyenda del pie de página, junto al año y al nombre. */
    'pie' => env('MARCA_PIE', 'Sistema interno confidencial'),

    /**
     * Vocabulario del negocio: cómo se llama cada cosa en esta instalación.
     *
     * El sistema nació para agentes de carga y habla de *bookings*, *buques* y
     * *contenedores*. Un taller no tiene buques; tiene órdenes de servicio y
     * equipos. Esto nombra una carpeta dentro de `lang/vocabulario/` cuyos
     * archivos **ganan sobre las traducciones de base**, así que cambiar el
     * vocabulario no toca ni una vista.
     *
     * Vacío = el vocabulario de origen (carga marítima).
     * Ver `docs/MARCA-BLANCA.md`.
     */
    'vocabulario' => env('MARCA_VOCABULARIO', ''),

    /**
     * Idioma de los documentos que salen de la empresa: PDF y correos.
     *
     * Va aparte del idioma de la interfaz a propósito — el documento lo lee el
     * cliente, no el operador—, y por omisión es `en` porque así los emitía el
     * sistema anterior y las pruebas de paridad los comparan carácter por
     * carácter. Una instalación nueva que facture en español pone `es`.
     */
    'idioma_documentos' => env('MARCA_IDIOMA_DOCUMENTOS', 'en'),

    /**
     * Qué catálogos ve esta instalación, por slug y separados por comas.
     *
     * El sistema trae diecisiete, y buena parte solo tienen sentido en carga
     * marítima: un taller no quiere «Navieras», «Puertos de carga», «Buques» ni
     * «Tipos de contenedor» estorbando en su menú.
     *
     * Vacío = todos. Los slugs son los de `CatalogRegistry`; se pueden ver en
     * `/catalogos`. Un catálogo que no esté en la lista tampoco se alcanza
     * escribiendo su dirección a mano.
     *
     * ⚠️ Un slug mal tecleado no falla: hace desaparecer el catálogo del menú en
     * silencio. `CatalogVisibilityTest` comprueba que todos existan.
     */
    'catalogos' => env('MARCA_CATALOGOS', ''),

    /**
     * Campos del expediente que esta instalación NO pide, separados por comas.
     *
     * Solo los opcionales; la lista está en `App\Support\Expediente::OPCIONALES`.
     * Un taller apagaría `carrierId,brokerId,containerType,setPoint`.
     *
     * El campo apagado desaparece del formulario y del detalle, no se valida y
     * no se escribe; lo ya guardado se queda como está.
     */
    /**
     * Cómo mueve esta empresa: `maritimo`, `terrestre`, o **las dos**.
     *
     * Las dos capacidades viven siempre en el sistema; esto decide cuáles se
     * enseñan. Una empresa que subcontrata el barco Y tiene camiones propios
     * pone las dos y ve todo:
     *
     *   MARCA_MODALIDADES=maritimo,terrestre
     *
     * · `maritimo`  → buque en el expediente, catálogo de buques.
     * · `terrestre` → operador, tractor y caja; catálogos de operadores y unidades.
     *
     * Por omisión solo `maritimo`, y no por preferencia: es lo único que existía
     * antes de que hubiera flota propia, y cambiar el valor por omisión le
     * añadiría tres selectores vacíos a toda instalación ya funcionando.
     */
    'modalidades' => env('MARCA_MODALIDADES', 'maritimo'),

    /**
     * De dónde sale el porcentaje de avance del expediente.
     *
     * · `hitos`        → cuántos pasos del catálogo de hitos tienen fecha. Es lo
     *                    que se enseña en el detalle y lo que entiende cualquiera.
     * · `verificacion` → las 27 casillas `_chk_date` heredadas, con su divisor
     *                    histórico de 26 (un expediente con las 27 marcadas da
     *                    103.85 %). **Solo para la instalación original**, donde
     *                    el cliente lleva años viendo ese número y cambiarlo se
     *                    lo movería todo de golpe.
     */
    'avance' => env('MARCA_AVANCE', 'hitos'),

    /**
     * Esta instalación es de DEMOSTRACIÓN.
     *
     * Enciende en Ajustes el botón que **borra la base y la vuelve a llenar**
     * con los datos de una vertical —marítimo o terrestre—, para enseñar el
     * sistema con los datos del negocio que se tiene enfrente.
     *
     * ⚠️ Jamás en la instalación de un cliente: ahí el botón no existe y la
     * acción se niega aunque se llame a mano.
     */
    'demo' => filter_var(env('MARCA_DEMO', false), FILTER_VALIDATE_BOOL),

    /**
     * Página pública de presentación (la portada con «Solicitar demostración»
     * y el contacto).
     *
     * Encendida (por omisión) la raíz muestra esa portada; apagada, la raíz va
     * directo al login. En la instalación de un cliente que entra por su propia
     * dirección —sin vender nada— se apaga: no hay nada que promocionar.
     */
    'landing' => filter_var(env('MARCA_LANDING', true), FILTER_VALIDATE_BOOL),

    /**
     * Taller: mantenimiento de las unidades y almacén de refacciones.
     *
     * Solo tiene sentido con flota propia, y ni siquiera siempre: quien manda
     * todo a un taller externo no lleva almacén. Se apaga entero —menú y
     * catálogo de refacciones— sin dejar pantallas a medias.
     */
    'taller' => filter_var(env('MARCA_TALLER', true), FILTER_VALIDATE_BOOL),

    /**
     * Nómina interna: la plantilla y lo que se le paga a cada quien.
     *
     * Se apaga en las instalaciones que ya llevan la nómina en otro sistema —que
     * son muchas—, porque una pantalla de nómina a medio usar es peor que no
     * tenerla: se captura ahí y se paga desde el otro lado.
     *
     * Ojo con lo que **no** hace: no calcula IMSS, INFONAVIT ni ISR, ni timbra
     * CFDI de nómina. Reúne lo que se paga y lo exporta al sistema fiscal.
     */
    'nomina' => filter_var(env('MARCA_NOMINA', true), FILTER_VALIDATE_BOOL),

    /**
     * Pantalla de Ajustes. Se apaga en la instalación de un cliente cuando lo
     * configura el dueño del producto: ni en el menú ni por dirección, aunque
     * quien entre sea super admin. Se vuelve a encender desde el `.env`.
     */
    'ajustes' => filter_var(env('MARCA_AJUSTES', true), FILTER_VALIDATE_BOOL),

    /**
     * Y por si hace falta afinar dentro de una modalidad: campos sueltos que
     * esta instalación no pide, aunque su modalidad esté encendida.
     */
    'expediente_ocultos' => env('MARCA_EXPEDIENTE_OCULTOS', ''),

    /**
     * Por qué atributos empareja el catálogo de precios con el expediente.
     *
     * El emparejador nació para rutas marítimas: precio por puerto de carga →
     * puerto de descarga → destino final → lugar de recolección. Un negocio que
     * cobre por otra cosa apaga las que no usa y sus precios dejan de estar
     * atados a una geografía que no tiene.
     *
     * Vacío = las cuatro, que es como funcionaba. El cliente y el proveedor
     * participan **siempre** y no se pueden apagar: sin ellos, un precio de un
     * proveedor se le aplicaría a otro.
     *
     * Claves: puerto_carga, puerto_descarga, destino_final, lugar_recoleccion.
     */
    'emparejador_dimensiones' => env('MARCA_EMPAREJADOR_DIMENSIONES', ''),

    /**
     * Dibujo de fondo del mapa de rutas: un SVG o PNG del mundo en proyección
     * equirectangular (2:1), servido por la propia aplicación.
     *
     * Viene uno puesto (`public/marca/mundo.svg`), generado desde Natural Earth
     * 110m —dominio público— **con la misma proyección que `RouteMap`**, que es
     * lo que hace que los continentes casen con los puertos. Se puede sustituir
     * por otro, pero tiene que ser equirectangular: uno en Mercator dejaría los
     * puntos fuera de sitio. Vacío = sin dibujo, solo retícula y rutas.
     */
    'mapa_fondo' => env('MARCA_MAPA_FONDO', 'marca/mundo.svg'),

    /*
    |---------------------------------------------------------------------
    | Logotipo
    |---------------------------------------------------------------------
    |
    | Hay dos modos y se elige solo: si hay imagen configurada se usa la
    | imagen; si no, se dibuja el logotipo de letra (que no necesita ningún
    | archivo y se ve bien en los dos temas). Así una instalación nueva
    | funciona sin que nadie tenga que diseñar nada, y quien tenga logotipo
    | solo suelta el archivo y pone la ruta.
    */

    'logo' => [

        /**
         * Logotipo de letra: la primera parte va en el color del texto y la
         * segunda en el color de acento, para que se lea como una marca y no
         * como un título. El símbolo es opcional y va también en acento.
         */
        'texto' => [
            'principal' => env('MARCA_LOGO_TEXTO') ?: 'Cargo',
            'acento' => env('MARCA_LOGO_ACENTO') ?: 'Suite',
            'simbolo' => env('MARCA_LOGO_SIMBOLO', ''),
        ],

        /**
         * Imagen. Rutas relativas a `public/` (p. ej. `marca/logo.svg`) o
         * direcciones completas. La versión oscura es opcional: si no se
         * pone, se usa la clara en los dos temas.
         *
         * Conviene un SVG de altura libre: el alto lo fija cada pantalla.
         */
        'imagen' => [
            'claro' => env('MARCA_LOGO_IMAGEN'),
            'oscuro' => env('MARCA_LOGO_IMAGEN_OSCURO'),
        ],
    ],

    /** Icono de la pestaña. Relativo a `public/` o dirección completa. */
    'favicon' => env('MARCA_FAVICON', 'favicon.ico'),

    /*
    |---------------------------------------------------------------------
    | Colores
    |---------------------------------------------------------------------
    |
    | Se aplican en caliente: el layout los pinta como variables CSS en el
    | <head>, y las utilidades de Tailwind ya emiten `var(--color-accent-500)`,
    | así que cambiar el color NO exige volver a compilar los estilos.
    |
    | Solo se toca el acento (los grises son neutros a propósito y sirven para
    | cualquier marca). `marca_claro` y `marca_oscuro` son el tono del acento
    | usado sobre texto en cada tema: en claro tiene que ser más oscuro para
    | que se lea, y en oscuro más claro.
    */

    'colores' => [
        /*
         * Ojo con el `.env`: un color va SIEMPRE entre comillas. Sin ellas,
         * dotenv toma la almohadilla del `#rrggbb` como el principio de un
         * comentario y la variable llega vacía. Por eso además se cae al valor
         * de fábrica con `?:` y no con el segundo argumento de `env()`: lo que
         * llega no es null, es cadena vacía.
         */
        'acento_400' => env('MARCA_COLOR_400') ?: '#f04452',
        'acento_500' => env('MARCA_COLOR_500') ?: '#e11d2a',
        'acento_600' => env('MARCA_COLOR_600') ?: '#c11020',
        'acento_700' => env('MARCA_COLOR_700') ?: '#9e0c19',
        'marca_claro' => env('MARCA_COLOR_TEXTO_CLARO') ?: '#c11020',
        'marca_oscuro' => env('MARCA_COLOR_TEXTO_OSCURO') ?: '#f04452',
    ],

    /*
    |---------------------------------------------------------------------
    | Empresa
    |---------------------------------------------------------------------
    |
    | Datos de quien opera el sistema. Salen en los documentos impresos y en
    | los correos que se le mandan a clientes y proveedores.
    */

    'empresa' => [
        'nombre' => env('MARCA_EMPRESA') ?: 'CargoSuite Demo, S.A. de C.V.',
        'sitio' => env('MARCA_SITIO', ''),

        /**
         * Ficha que encabeza los documentos impresos (confirmación de booking y
         * solicitud de pago). En el sistema original iba escrita dentro de las
         * plantillas, incluido el RFC, aunque el sistema ya facturaba con
         * varias compañías.
         *
         * Un campo vacío no se pinta: quien no tenga RFC —una instalación fuera
         * de México— no ve el renglón.
         */
        'domicilio' => env('MARCA_DOMICILIO', 'Av. Siempre Viva 123, Col. Centro'),
        'rfc' => env('MARCA_RFC', ''),
        'telefono' => env('MARCA_TELEFONO', ''),

        /**
         * Dirección del portal de clientes y proveedores, la que se les manda
         * en el correo de confirmación. Vacía = se usa `APP_URL` + `/portal`.
         */
        'portal' => env('MARCA_PORTAL', ''),
    ],

    /*
    |---------------------------------------------------------------------
    | Correo
    |---------------------------------------------------------------------
    |
    | En el sistema original estas direcciones estaban escritas dentro de los
    | controladores, cada una por su cuenta: el remitente en cuatro sitios y
    | los avisos en dos.
    */

    'correo' => [

        /** Remitente de todos los correos del sistema. */
        'remitente' => [
            'direccion' => env('MARCA_MAIL_FROM') ?: env('MAIL_FROM_ADDRESS', 'facturas@ejemplo.com'),
            'nombre' => env('MARCA_MAIL_FROM_NAME') ?: (env('MARCA_NOMBRE') ?: env('APP_NAME', 'CargoSuite')),
        ],

        /**
         * Copia oculta de las facturas que se mandan al cliente, y único
         * destinatario mientras el timbrado está en pruebas
         * (`TIMBRADO_PRODUCCION=false`): ahí el cliente no debe recibir nada.
         */
        'copia_facturas' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MARCA_MAIL_FACTURAS_BCC', env('FREGO_MAIL_FACTURAS_BCC', ''))),
        ))),

        /** A dónde llegan los avisos de tareas atrasadas de operación. */
        'avisos_operacion' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MARCA_MAIL_AVISOS', env('FREGO_MAIL_AVISOS', ''))),
        ))),

        /**
         * ¿Los avisos de tareas atrasadas se mandan además por correo?
         *
         * Apagado por omisión: la bandeja de la aplicación (`/avisos`) enseña
         * lo mismo sin llenarle el buzón a nadie. Encenderlo manda **todo lo
         * vencido acumulado** en la primera corrida.
         */
        'avisos_por_correo' => env('MARCA_AVISOS_CORREO', env('FREGO_AVISOS_CORREO', false)),
    ],
];
