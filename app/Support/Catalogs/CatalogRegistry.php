<?php

namespace App\Support\Catalogs;

use App\Support\Expediente;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Los catálogos maestros del sistema, en un solo lugar.
 *
 * Cada entrada corresponde a una opción del menú «Options» del sistema original.
 * Agregar un catálogo nuevo es agregar una entrada aquí: no hace falta
 * controlador, vista ni ruta.
 *
 * Quedan fuera a propósito los que no son un catálogo plano y necesitan pantalla
 * propia: clientes, proveedores, servicios (3 128 filas ligadas a un cliente o
 * proveedor y a un tipo de cargo), usuarios y el tipo de cambio.
 */
class CatalogRegistry
{
    /** @return array<string, CatalogDefinition> */
    public static function all(): array
    {
        $texto = ['required', 'string', 'max:100'];
        $textoOpcional = ['nullable', 'string', 'max:255'];

        $definiciones = [
            new CatalogDefinition(
                slug: 'puertos-carga',
                table: 'loading_ports',
                key: 'port_id',
                singular: __('Puerto de carga'),
                plural: __('Puertos de carga'),
                fields: [
                    new CatalogField('port_name', __('Nombre'), rules: $texto),
                    new CatalogField('latitud', __('Latitud'), type: 'number', rules: ['nullable', 'numeric', 'between:-90,90'], inList: false),
                    new CatalogField('longitud', __('Longitud'), type: 'number', rules: ['nullable', 'numeric', 'between:-180,180'], inList: false),
                ],
                softDelete: 'deleted',
            ),
            new CatalogDefinition(
                slug: 'puertos-descarga',
                table: 'dicharge_port',
                key: 'dicharge_port_id',
                singular: __('Puerto de descarga'),
                plural: __('Puertos de descarga'),
                fields: [
                    new CatalogField('name', __('Nombre'), rules: $texto),
                    new CatalogField('latitud', __('Latitud'), type: 'number', rules: ['nullable', 'numeric', 'between:-90,90'], inList: false),
                    new CatalogField('longitud', __('Longitud'), type: 'number', rules: ['nullable', 'numeric', 'between:-180,180'], inList: false),
                ],
                softDelete: 'deleted',
            ),
            new CatalogDefinition(
                slug: 'destinos-finales',
                table: 'final_destination',
                key: 'final_destination_id',
                singular: __('Destino final'),
                plural: __('Destinos finales'),
                fields: [
                    new CatalogField('name', __('Nombre'), rules: ['required', 'string', 'max:64']),
                    new CatalogField('latitud', __('Latitud'), type: 'number', rules: ['nullable', 'numeric', 'between:-90,90'], inList: false),
                    new CatalogField('longitud', __('Longitud'), type: 'number', rules: ['nullable', 'numeric', 'between:-180,180'], inList: false),
                ],
                softDelete: 'deleted',
            ),
            new CatalogDefinition(
                slug: 'buques',
                table: 'vessel',
                key: 'vessel_id',
                singular: __('Buque'),
                plural: __('Buques'),
                fields: [new CatalogField('vessel_name', __('Nombre'), rules: $texto)],
            ),
            new CatalogDefinition(
                slug: 'tipos-contenedor',
                table: 'container_types',
                key: 'contType_id',
                singular: __('Tipo de contenedor'),
                plural: __('Tipos de contenedor'),
                fields: [new CatalogField('container_name', __('Nombre'), rules: ['required', 'string', 'max:25'])],
            ),
            new CatalogDefinition(
                slug: 'navieras',
                table: 'carrier',
                key: 'carrier_id',
                singular: __('Naviera'),
                plural: __('Navieras'),
                fields: [
                    new CatalogField('name', __('Nombre'), rules: $texto),
                    new CatalogField('email', __('Correo'), rules: ['nullable', 'email', 'max:50']),
                ],
                audited: true,
                searchable: ['name', 'email'],
                // La tabla guarda además contraseña y llaves de acceso del portal;
                // no se exponen aquí porque este catálogo es solo el directorio.
                note: __('Los accesos al portal de la naviera se administran aparte.'),
            ),
            /*
             * Flota propia. Un agente de carga subcontrata y no los usa; una
             * empresa de camiones no puede operar sin ellos. Se ocultan con
             * `MARCA_CATALOGOS` en las instalaciones que no tienen flota.
             *
             * Las fechas de vencimiento son lo que de verdad se opera: una
             * licencia o un seguro vencidos detienen un viaje.
             */
            new CatalogDefinition(
                slug: 'operadores',
                table: 'operador',
                key: 'operador_id',
                singular: __('Operador'),
                plural: __('Operadores'),
                fields: [
                    new CatalogField('nombre', __('Nombre'), rules: ['required', 'string', 'max:120']),
                    new CatalogField('numero', __('Número'), rules: ['nullable', 'string', 'max:30']),
                    new CatalogField('telefono', __('Teléfono'), rules: ['nullable', 'string', 'max:40']),
                    new CatalogField('licencia', __('Licencia'), rules: ['nullable', 'string', 'max:40']),
                    new CatalogField('licencia_tipo', __('Tipo de licencia'), rules: ['nullable', 'string', 'max:20'], inList: false),
                    new CatalogField('licencia_vence', __('Vence la licencia'), type: 'date', rules: ['nullable', 'date']),
                    new CatalogField('examen_medico_vence', __('Vence el examen médico'), type: 'date', rules: ['nullable', 'date'], inList: false),
                    new CatalogField('rfc', __('RFC'), rules: ['nullable', 'string', 'max:20'], inList: false),
                    new CatalogField('curp', __('CURP'), rules: ['nullable', 'string', 'max:20'], inList: false),
                    new CatalogField('nss', __('NSS'), rules: ['nullable', 'string', 'max:20'], inList: false),
                    new CatalogField('ingreso', __('Ingreso'), type: 'date', rules: ['nullable', 'date'], inList: false),
                    new CatalogField('notas', __('Notas'), rules: ['nullable', 'string', 'max:255'], inList: false),
                    new CatalogField('activo', __('Activo'), type: 'boolean', rules: ['boolean']),
                ],
            ),
            new CatalogDefinition(
                slug: 'unidades',
                table: 'unidad',
                key: 'unidad_id',
                singular: __('Unidad'),
                plural: __('Unidades'),
                fields: [
                    new CatalogField('numero', __('Número económico'), rules: ['required', 'string', 'max:30']),
                    new CatalogField('tipo', __('Tipo'), rules: ['required', 'string', 'max:20']),
                    new CatalogField('placas', __('Placas'), rules: ['nullable', 'string', 'max:20']),
                    new CatalogField('marca', __('Marca'), rules: ['nullable', 'string', 'max:40']),
                    new CatalogField('modelo', __('Modelo'), rules: ['nullable', 'string', 'max:40'], inList: false),
                    new CatalogField('anio', __('Año'), rules: ['nullable', 'string', 'max:4']),
                    new CatalogField('serie', __('Número de serie'), rules: ['nullable', 'string', 'max:40'], inList: false),
                    new CatalogField('permiso_sct', __('Permiso SCT'), rules: ['nullable', 'string', 'max:40'], inList: false),
                    new CatalogField('seguro_vence', __('Vence el seguro'), type: 'date', rules: ['nullable', 'date']),
                    new CatalogField('verificacion_vence', __('Vence la verificación'), type: 'date', rules: ['nullable', 'date'], inList: false),
                    new CatalogField('kilometraje', __('Kilometraje'), type: 'number', rules: ['nullable', 'numeric']),
                    new CatalogField('notas', __('Notas'), rules: ['nullable', 'string', 'max:255'], inList: false),
                    new CatalogField('activo', __('Activo'), type: 'boolean', rules: ['boolean']),
                ],
            ),
            /*
             * Los hitos del expediente. Antes eran catorce columnas de
             * `booking_continuity` y cambiarlos exigía una migración; ahora se
             * capturan aquí, que es lo que permite que el módulo de continuidad
             * sirva a un negocio que no sea de carga marítima.
             *
             * `clave` es el identificador interno y no se toca en los heredados:
             * de él cuelga la columna que se sigue escribiendo por compatibilidad
             * (ver `App\Support\Milestones\BookingMilestones`).
             */
            new CatalogDefinition(
                slug: 'hitos',
                table: 'hito',
                key: 'hito_id',
                singular: __('Hito'),
                plural: __('Hitos'),
                fields: [
                    new CatalogField('etiqueta', __('Nombre'), rules: ['required', 'string', 'max:60']),
                    new CatalogField('clave', __('Clave'), rules: ['required', 'string', 'max:40']),
                    new CatalogField('orden', __('Orden'), type: 'number', rules: ['required', 'numeric']),
                    new CatalogField('activo', __('Activo'), type: 'boolean', rules: ['boolean']),
                ],
            ),
            /*
             * Los campos propios del expediente: lo que este negocio pide y el
             * sistema no traía. Se guardan como filas en `valor_por_expediente`,
             * así que añadir uno no toca el esquema.
             */
            new CatalogDefinition(
                slug: 'campos-expediente',
                table: 'campo_expediente',
                key: 'campo_id',
                singular: __('Campo del expediente'),
                plural: __('Campos del expediente'),
                fields: [
                    new CatalogField('etiqueta', __('Nombre'), rules: ['required', 'string', 'max:60']),
                    new CatalogField('clave', __('Clave'), rules: ['required', 'string', 'max:40']),
                    new CatalogField('tipo', __('Tipo'), rules: ['required', 'string', 'max:10']),
                    new CatalogField('opciones', __('Opciones (separadas por |)'), rules: ['nullable', 'string', 'max:255']),
                    new CatalogField('grupo', __('Apartado'), rules: ['nullable', 'string', 'max:40']),
                    new CatalogField('orden', __('Orden'), type: 'number', rules: ['required', 'numeric']),
                    new CatalogField('obligatorio', __('Obligatorio'), type: 'boolean', rules: ['boolean']),
                    new CatalogField('activo', __('Activo'), type: 'boolean', rules: ['boolean']),
                ],
            ),
            new CatalogDefinition(
                slug: 'modalidades',
                table: 'modality',
                key: 'modality_id',
                singular: __('Modalidad'),
                plural: __('Modalidades'),
                fields: [new CatalogField('modality_name', __('Nombre'), rules: ['required', 'string', 'max:15'])],
            ),
            new CatalogDefinition(
                slug: 'terminos-pago',
                table: 'payment_terms',
                key: 'pay_terms_id',
                singular: __('Término de pago'),
                plural: __('Términos de pago'),
                fields: [new CatalogField('pay_terms', __('Término'), rules: ['required', 'string', 'max:50'])],
            ),
            new CatalogDefinition(
                slug: 'lugares-recoleccion',
                table: 'pickup_place',
                key: 'pick_id',
                singular: __('Lugar de recolección'),
                plural: __('Lugares de recolección'),
                fields: [
                    new CatalogField('name', __('Nombre'), rules: $texto),
                    new CatalogField('address1', __('Dirección'), rules: $textoOpcional),
                    new CatalogField('address2', __('Dirección 2'), rules: $textoOpcional, inList: false),
                    new CatalogField('city', __('Ciudad'), rules: ['nullable', 'string', 'max:25']),
                    new CatalogField('state', __('Estado o provincia'), rules: ['nullable', 'string', 'max:25']),
                    new CatalogField('country', __('País'), rules: ['nullable', 'string', 'max:25'], inList: false),
                    new CatalogField('postal_code', __('Código postal'), rules: ['nullable', 'string', 'max:25'], inList: false),
                    new CatalogField('latitud', __('Latitud'), type: 'number', rules: ['nullable', 'numeric', 'between:-90,90'], inList: false),
                    new CatalogField('longitud', __('Longitud'), type: 'number', rules: ['nullable', 'numeric', 'between:-180,180'], inList: false),
                ],
                audited: true,
                searchable: ['name', 'city', 'state'],
            ),
            new CatalogDefinition(
                slug: 'dias-festivos',
                table: 'holiday',
                key: 'holiday_id',
                singular: __('Día festivo'),
                plural: __('Días festivos'),
                fields: [
                    new CatalogField('name', __('Nombre'), rules: $texto),
                    new CatalogField('start_date', __('Desde'), type: 'date', rules: ['required', 'date']),
                    new CatalogField('end_date', __('Hasta'), type: 'date', rules: ['nullable', 'date', 'after_or_equal:form.start_date']),
                ],
                orderBy: 'start_date',
                audited: true,
            ),
            new CatalogDefinition(
                slug: 'monedas',
                table: 'account',
                key: 'account_id',
                singular: __('Moneda'),
                plural: __('Monedas'),
                fields: [
                    new CatalogField('account_name', __('Nombre'), rules: ['required', 'string', 'max:50']),
                    new CatalogField('prefix', __('Prefijo'), rules: ['nullable', 'string', 'max:6']),
                    new CatalogField('default', __('Moneda base'), type: 'boolean', rules: ['boolean']),
                ],
                orderBy: 'account_id',
                searchable: ['account_name', 'prefix'],
                note: __('La moneda base es la que no se convierte: su tipo de cambio vale 1.'),
            ),
            new CatalogDefinition(
                slug: 'companias',
                table: 'company',
                key: 'company_id',
                singular: __('Compañía'),
                plural: __('Compañías emisoras'),
                fields: [
                    new CatalogField('name', __('Nombre corto'), rules: ['required', 'string', 'max:150']),
                    new CatalogField('business_name', __('Razón social'), rules: $textoOpcional),
                    new CatalogField('rfc', __('RFC'), rules: ['nullable', 'string', 'max:15']),
                    new CatalogField('regimen_fiscal', __('Régimen fiscal'), rules: ['nullable', 'string', 'max:5'], inList: false),
                    new CatalogField('postal_code', __('Código postal'), rules: ['nullable', 'string', 'max:10'], inList: false),
                    new CatalogField('address', __('Dirección'), rules: $textoOpcional, inList: false),
                    new CatalogField('active', __('Activa'), type: 'boolean', rules: ['boolean']),
                ],
                orderBy: 'name',
                searchable: ['name', 'business_name', 'rfc'],
                note: __('El RFC, el régimen y el código postal son los que salen en el CFDI.'),
            ),
            new CatalogDefinition(
                slug: 'tipos-cargo',
                table: 'charge_type',
                key: 'charge_type_id',
                singular: __('Tipo de cargo'),
                plural: __('Tipos de cargo e impuestos'),
                fields: [
                    new CatalogField('charge_type_name', __('Nombre'), rules: ['required', 'string', 'max:25']),
                    new CatalogField('tax_name', __('Nombre del impuesto'), rules: ['nullable', 'string', 'max:25']),
                    new CatalogField('tax_rate', __('Tasa de IVA'), type: 'number', rules: ['required', 'numeric', 'between:0,1']),
                    new CatalogField('tax_retention', __('Retención'), type: 'number', rules: ['required', 'numeric', 'between:0,1']),
                    new CatalogField('product_code', __('Clave de producto (SAT)'), rules: ['nullable', 'string', 'max:64'], inList: false),
                    new CatalogField('non_deductible', __('No deducible'), type: 'boolean', rules: ['boolean']),
                ],
                softDelete: 'deleted',
                orderBy: 'charge_type_name',
                searchable: ['charge_type_name', 'tax_name'],
                note: __('La tasa va en proporción: 0.16 es 16 %. De aquí sale en qué cubeta cae cada concepto.'),
            ),
            new CatalogDefinition(
                slug: 'bancos',
                table: 'bank',
                key: 'bank_id',
                singular: __('Banco'),
                plural: __('Bancos'),
                fields: [
                    new CatalogField('bank_name', __('Nombre'), rules: $texto),
                    new CatalogField('account_number', __('Número de cuenta'), rules: ['nullable', 'string', 'max:64']),
                    new CatalogField('active', __('Activo'), type: 'boolean', rules: ['boolean']),
                    new CatalogField('default', __('Predeterminado'), type: 'boolean', rules: ['boolean']),
                ],
                orderBy: 'bank_name',
                audited: true,
                searchable: ['bank_name', 'account_number'],
            ),
            new CatalogDefinition(
                slug: 'campos-archivo',
                table: 'file_fields',
                key: 'field_id',
                singular: __('Campo de archivo'),
                plural: __('Campos de archivo'),
                fields: [
                    new CatalogField('field', __('Campo'), rules: ['required', 'string', 'max:25']),
                    new CatalogField('label', __('Etiqueta'), rules: ['nullable', 'string', 'max:25']),
                    new CatalogField('default', __('Por omisión'), type: 'boolean', rules: ['boolean']),
                ],
                orderBy: 'label',
                searchable: ['field', 'label'],
            ),
            new CatalogDefinition(
                slug: 'codigos-impuesto',
                table: 'tax_code',
                key: 'tax_code_id',
                singular: __('Código de impuesto'),
                plural: __('Códigos de impuesto'),
                fields: [
                    new CatalogField('tax_code', __('Código'), rules: ['required', 'string', 'max:255']),
                    new CatalogField('tax_rate', __('Tasa'), type: 'number', rules: ['nullable', 'numeric']),
                    new CatalogField('tax_retention', __('Retención'), rules: $textoOpcional),
                ],
                orderBy: 'tax_code',
            ),
        ];

        return collect($definiciones)->keyBy(fn (CatalogDefinition $d) => $d->slug)->all();
    }

    /**
     * Los catálogos que esta instalación enseña (`marca.catalogos`).
     *
     * `all()` sigue devolviendo TODOS a propósito: de ahí come el guardián que
     * valida cada definición contra el esquema real, y dejar de mostrar un
     * catálogo no debe dejarlo sin vigilar.
     *
     * Se respeta el orden del registro, no el del `.env`: el menú se lee mejor
     * si los catálogos salen siempre en el mismo sitio.
     *
     * @return array<string, CatalogDefinition>
     */
    /** Catálogos que solo tienen sentido en una modalidad de transporte. */
    private const POR_MODALIDAD = [
        'maritimo' => ['buques'],
        'terrestre' => ['operadores', 'unidades'],
    ];

    public static function visibles(): array
    {
        $elegidos = array_filter(array_map('trim', explode(',', (string) config('marca.catalogos'))));

        $catalogos = $elegidos === []
            ? self::all()
            : array_intersect_key(self::all(), array_flip($elegidos));

        // Fuera los de una modalidad apagada: un agente de carga no administra
        // operadores, y una empresa de camiones no administra buques.
        $modalidades = Expediente::modalidades();

        foreach (self::POR_MODALIDAD as $modalidad => $slugs) {
            if (! in_array($modalidad, $modalidades, true)) {
                $catalogos = array_diff_key($catalogos, array_flip($slugs));
            }
        }

        return $catalogos;
    }

    /**
     * Resuelve sobre los visibles y no sobre todos: si no, un catálogo oculto
     * seguiría siendo alcanzable escribiendo su dirección a mano.
     */
    public static function find(string $slug): CatalogDefinition
    {
        return self::visibles()[$slug] ?? throw new NotFoundHttpException("No existe el catálogo «{$slug}».");
    }
}
