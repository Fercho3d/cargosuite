<?php

namespace App\Support\Catalogs;

use App\Models\Core\Company;
use App\Support\Cfdi\RegimenesFiscales;
use App\Support\Expediente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        $deducible = fn (array $form) => ! ($form['non_deductible'] ?? false);

        // `unique: true` en el nombre solo donde la base real no tiene repetidos.
        // Puertos de carga y descarga, destinos finales, buques y tipos de cargo
        // ya traen nombres duplicados del sistema viejo y ahí no se exige; días
        // festivos, bancos y personas repiten nombre con razón.

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
                superAdmin: true,
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
                usedBy: [['booking', 'vessel']],
            ),
            new CatalogDefinition(
                slug: 'tipos-contenedor',
                table: 'container_types',
                key: 'contType_id',
                singular: __('Tipo de contenedor'),
                plural: __('Tipos de contenedor'),
                fields: [new CatalogField('container_name', __('Nombre'), rules: ['required', 'string', 'max:25'], unique: true)],
                // `containers.container_type` es `ON DELETE CASCADE`: borrar un tipo
                // en uso borraría los contenedores de todos sus bookings.
                usedBy: [['containers', 'container_type'], ['booking', 'container_type'], ['service', 'container_type_id']],
            ),
            new CatalogDefinition(
                slug: 'navieras',
                table: 'carrier',
                key: 'carrier_id',
                singular: __('Naviera'),
                plural: __('Navieras'),
                fields: [
                    new CatalogField('name', __('Nombre'), rules: $texto, unique: true),
                    // `email` es `NOT NULL` y es el usuario del portal: sin él o repetido, la
                    // naviera no podría entrar.
                    new CatalogField('email', __('Correo'), rules: ['required', 'email', 'max:50'], unique: true),
                ],
                audited: true,
                searchable: ['name', 'email'],
                usedBy: [['carrier_booking', 'carrier_id']],
                // La tabla guarda además contraseña y llaves de acceso del portal;
                // no se exponen aquí porque este catálogo es solo el directorio.
                // `password` es `NOT NULL`: se escribe una al azar que nadie conoce y
                // la real se fija desde Usuarios.
                insertDefaults: fn () => ['password' => Hash::make(Str::random(32))],
                note: __('La contraseña del portal de la naviera se fija desde Usuarios, no aquí.'),
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
                    new CatalogField('numero', __('Número económico'), rules: ['required', 'string', 'max:30'], unique: true),
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
             * Las refacciones del almacén.
             *
             * El alta vive aquí —es un catálogo— pero la EXISTENCIA no se toca
             * desde esta pantalla: se mueve en el almacén, que deja su rastro en
             * el kárdex. Editarla a mano dejaría el inventario descuadrado sin
             * que nadie supiera desde cuándo.
             */
            new CatalogDefinition(
                slug: 'refacciones',
                table: 'refaccion',
                key: 'refaccion_id',
                singular: __('Refacción'),
                plural: __('Refacciones'),
                fields: [
                    new CatalogField('codigo', __('Código'), rules: ['required', 'string', 'max:40'], unique: true),
                    new CatalogField('nombre', __('Nombre'), rules: ['required', 'string', 'max:120']),
                    new CatalogField('categoria', __('Categoría'), rules: ['nullable', 'string', 'max:40']),
                    new CatalogField('medida', __('Medida'), rules: ['nullable', 'string', 'max:20']),
                    new CatalogField('ubicacion', __('Ubicación'), rules: ['nullable', 'string', 'max:40']),
                    new CatalogField('minimo', __('Mínimo'), type: 'number', rules: ['nullable', 'numeric', 'min:0']),
                    new CatalogField('costo', __('Costo'), type: 'number', rules: ['nullable', 'numeric', 'min:0']),
                    new CatalogField('notas', __('Notas'), rules: ['nullable', 'string', 'max:255'], inList: false),
                    new CatalogField('activo', __('Activo'), type: 'boolean', rules: ['boolean']),
                ],
                orderBy: 'nombre',
            ),
            /*
             * La plantilla de la empresa. Es la base de la nómina interna y por
             * eso vive aquí y no dentro de ella: el alta de una persona la hace
             * quien administra catálogos, no quien corre la nómina.
             *
             * `operador_id` es el puente con la flota: al ligar a un empleado
             * con su operador, sus liquidaciones de viaje entran solas a la
             * nómina del periodo y ya no se recapturan a mano.
             */
            new CatalogDefinition(
                slug: 'empleados',
                table: 'empleado',
                key: 'empleado_id',
                singular: __('Empleado'),
                plural: __('Empleados'),
                fields: [
                    new CatalogField('nombre', __('Nombre'), rules: ['required', 'string', 'max:120']),
                    new CatalogField('numero', __('Número'), rules: ['nullable', 'string', 'max:30']),
                    new CatalogField('puesto', __('Puesto'), rules: ['nullable', 'string', 'max:60']),
                    new CatalogField('departamento', __('Departamento'), rules: ['nullable', 'string', 'max:60'], inList: false),
                    new CatalogField(
                        'operador_id',
                        __('Operador de la flota'),
                        type: 'select',
                        rules: ['nullable', 'integer', 'exists:operador,operador_id'],
                        inList: false,
                        options: fn () => Schema::hasTable('operador')
                            ? DB::table('operador')->where('activo', 1)->orderBy('nombre')->pluck('nombre', 'operador_id')->all()
                            : [],
                    ),
                    new CatalogField('salario_diario', __('Salario diario'), type: 'number', rules: ['nullable', 'numeric', 'min:0']),
                    new CatalogField('ingreso', __('Ingreso'), type: 'date', rules: ['nullable', 'date'], inList: false),
                    new CatalogField('rfc', __('RFC'), rules: ['nullable', 'string', 'max:20'], inList: false),
                    new CatalogField('curp', __('CURP'), rules: ['nullable', 'string', 'max:20'], inList: false),
                    new CatalogField('nss', __('NSS'), rules: ['nullable', 'string', 'max:20'], inList: false),
                    new CatalogField('banco', __('Banco'), rules: ['nullable', 'string', 'max:40'], inList: false),
                    new CatalogField('clabe', __('CLABE'), rules: ['nullable', 'string', 'max:20'], inList: false),
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
                fields: [new CatalogField('modality_name', __('Nombre'), rules: ['required', 'string', 'max:15'], unique: true)],
                // `booking_continuity.modality` es `ON DELETE CASCADE`: borrar una
                // modalidad en uso borraría la continuidad de todos sus bookings.
                usedBy: [['booking_continuity', 'modality']],
            ),
            new CatalogDefinition(
                slug: 'terminos-pago',
                table: 'payment_terms',
                key: 'pay_terms_id',
                singular: __('Término de pago'),
                plural: __('Términos de pago'),
                fields: [new CatalogField('pay_terms', __('Término'), rules: ['required', 'string', 'max:50'], unique: true)],
                usedBy: [['transaction', 'payment_terms']],
            ),
            new CatalogDefinition(
                slug: 'lugares-recoleccion',
                table: 'pickup_place',
                key: 'pick_id',
                singular: __('Lugar de recolección'),
                plural: __('Lugares de recolección'),
                fields: [
                    new CatalogField('name', __('Nombre'), rules: $texto, unique: true),
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
                usedBy: [['booking', 'pick_up_place_id'], ['service', 'pickup_place_id']],
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
                superAdmin: true,
            ),
            new CatalogDefinition(
                slug: 'monedas',
                table: 'account',
                key: 'account_id',
                singular: __('Moneda'),
                plural: __('Monedas'),
                fields: [
                    new CatalogField('account_name', __('Nombre'), rules: ['required', 'string', 'max:50'], unique: true),
                    new CatalogField('prefix', __('Prefijo'), rules: ['nullable', 'string', 'max:6']),
                    new CatalogField('default', __('Moneda base'), type: 'boolean', rules: ['boolean']),
                ],
                orderBy: 'account_id',
                searchable: ['account_name', 'prefix'],
                usedBy: [
                    ['transaction', 'account'], ['service', 'account_id'], ['exchange', 'account'],
                    ['client', 'account_id'], ['provider', 'account_id'], ['payment_request', 'currency_id'],
                ],
                note: __('La moneda base es la que no se convierte: su tipo de cambio vale 1.'),
                superAdmin: true,
            ),
            new CatalogDefinition(
                slug: 'companias',
                table: 'company',
                key: 'company_id',
                singular: __('Compañía'),
                plural: __('Compañías emisoras'),
                fields: [
                    new CatalogField('name', __('Nombre corto'), rules: ['required', 'string', 'max:150'], unique: true),
                    new CatalogField('business_name', __('Razón social'), rules: $textoOpcional),
                    // Los tres datos que viajan al CFDI, con el formato que exige el SAT.
                    new CatalogField('rfc', __('RFC'), rules: ['nullable', 'string', 'max:15', 'regex:'.RegimenesFiscales::RFC_REGEX]),
                    // Mismo default que la columna (`DEFAULT '601'`): sin él, una compañía
                    // nueva nacía sin régimen y su CFDI salía incompleto.
                    new CatalogField(
                        'regimen_fiscal',
                        __('Régimen fiscal'),
                        type: 'select',
                        rules: ['nullable', 'string', 'max:5', Rule::in(array_keys(RegimenesFiscales::all()))],
                        inList: false,
                        options: fn () => RegimenesFiscales::options(),
                        default: '601',
                    ),
                    new CatalogField('postal_code', __('Código postal'), rules: ['nullable', 'string', 'max:10', 'regex:/^\d{5}$/'], inList: false),
                    new CatalogField('address', __('Dirección'), rules: $textoOpcional, inList: false),
                    // Nace activa, como en el original: si no, no aparece en ningún selector.
                    new CatalogField('active', __('Activa'), type: 'boolean', rules: ['boolean'], default: true),
                ],
                orderBy: 'name',
                searchable: ['name', 'business_name', 'rfc'],
                usedBy: [['transaction', 'company_id']],
                note: __('El RFC, el régimen y el código postal son los que salen en el CFDI.'),
                superAdmin: true,
                // El semáforo del original: sin los cuatro datos fiscales el PAC rechaza el CFDI.
                badges: [
                    __('Facturación') => function (object $fila): array {
                        $faltan = (new Company)->setRawAttributes((array) $fila)->missingFiscalFields();

                        return $faltan === []
                            ? [__('Lista para facturar'), true]
                            : [__('No factura: falta :campos', ['campos' => implode(', ', $faltan)]), false];
                    },
                ],
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
                    // Un cargo no deducible no lleva impuestos: se ocultan y se guardan en 0,
                    // como hacía `ChargeTypeController` en Yii2.
                    new CatalogField('tax_rate', __('Tasa de IVA'), type: 'number', rules: ['required', 'numeric', 'between:0,1'], visibleWhen: $deducible, hiddenValue: 0),
                    new CatalogField('tax_retention', __('Retención'), type: 'number', rules: ['required', 'numeric', 'between:0,1'], visibleWhen: $deducible, hiddenValue: 0),
                    new CatalogField('product_code', __('Clave de producto (SAT)'), rules: ['nullable', 'string', 'max:64'], inList: false),
                    new CatalogField('non_deductible', __('No deducible'), type: 'boolean', rules: ['boolean']),
                ],
                softDelete: 'deleted',
                orderBy: 'charge_type_name',
                searchable: ['charge_type_name', 'tax_name'],
                note: __('La tasa va en proporción: 0.16 es 16 %. De aquí sale en qué cubeta cae cada concepto.'),
                superAdmin: true,
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
                    new CatalogField('active', __('Activo'), type: 'boolean', rules: ['boolean'], default: true),
                    new CatalogField('default', __('Predeterminado'), type: 'boolean', rules: ['boolean']),
                ],
                orderBy: 'bank_name',
                audited: true,
                searchable: ['bank_name', 'account_number'],
                usedBy: [['payment_request', 'bank_id'], ['transaction', 'bank_id'], ['bank_entry', 'bank_id'], ['liquidacion', 'bank_id']],
            ),
            new CatalogDefinition(
                slug: 'campos-archivo',
                table: 'file_fields',
                key: 'field_id',
                singular: __('Campo de archivo'),
                plural: __('Campos de archivo'),
                fields: [
                    new CatalogField('field', __('Campo'), rules: ['required', 'string', 'max:25'], unique: true),
                    new CatalogField('label', __('Etiqueta'), rules: ['nullable', 'string', 'max:25']),
                    new CatalogField('default', __('Por omisión'), type: 'boolean', rules: ['boolean']),
                ],
                orderBy: 'label',
                searchable: ['field', 'label'],
                superAdmin: true,
            ),
            new CatalogDefinition(
                slug: 'codigos-impuesto',
                table: 'tax_code',
                key: 'tax_code_id',
                singular: __('Código de impuesto'),
                plural: __('Códigos de impuesto'),
                fields: [
                    new CatalogField('tax_code', __('Código'), rules: ['required', 'string', 'max:255'], unique: true),
                    new CatalogField('tax_rate', __('Tasa'), type: 'number', rules: ['nullable', 'numeric']),
                    // La columna es `varchar(255)` por herencia, pero lo que se guarda es una tasa.
                    new CatalogField('tax_retention', __('Retención'), type: 'number', rules: ['nullable', 'numeric']),
                ],
                orderBy: 'tax_code',
                // El cargo guarda el código como texto, no el id.
                usedBy: [['charge', 'tax_code', 'tax_code']],
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
        'terrestre' => ['operadores', 'unidades', 'refacciones'],
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

        // La plantilla solo sirve para la nómina: sin ella es una lista de
        // personas que nadie mira.
        if (! config('marca.nomina')) {
            unset($catalogos['empleados']);
        }

        // Y las refacciones, para el taller.
        if (! config('marca.taller')) {
            unset($catalogos['refacciones']);
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
