<?php

namespace Tests\Support;

use App\Support\Milestones\MilestoneCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconstruye, en la conexión de pruebas, las tablas del módulo Transactions con
 * los mismos nombres y tipos que el esquema heredado de Yii2.
 *
 * Sirve para probar la aritmética con datos diminutos hechos a mano, donde el
 * resultado esperado se puede calcular a lápiz. Las pruebas de paridad, en cambio,
 * corren contra la base real; las dos cosas se complementan.
 */
class CoreSchema
{
    /**
     * Tabla `users` heredada de Yii2. Va aparte porque la mayoría de las pruebas
     * no la necesita: para autenticar basta un modelo en memoria.
     */
    /**
     * Los hitos del sistema de origen. Las pruebas los necesitan sembrados:
     * desde que dejaron de ser columnas, sin catálogo no hay rejilla que pintar
     * ni hito que validar.
     */
    public static function seedHitos(): void
    {
        $orden = 0;

        foreach ([
            // La maniobra de vacío («Empty Pass») va antes de la recolección,
            // como en el formulario de continuidad del sistema de origen.
            'vacuum_maneuver' => 'Maniobra de vacío',
            'pickup_date' => 'Recolección',
            'doc_cut_of' => 'Corte documental',
            'SI_date' => 'Instrucciones',
            'draf_client' => 'Draft cliente',
            'corrected_draft' => 'Draft corregido',
            'vgm' => 'VGM',
            'gated_IN' => 'Gate in',
            'cleared' => 'Despacho',
            'departure' => 'Zarpe',
            'bl_payment' => 'Pago del BL',
            'swb' => 'SWB',
            'delivered' => 'Entregado',
            'gated_out' => 'Gate out',
            'insurance' => 'Seguro',
        ] as $clave => $etiqueta) {
            $orden += 10;

            DB::table('hito')->insert([
                'clave' => $clave,
                'etiqueta' => $etiqueta,
                'orden' => $orden,
                'activo' => 1,
                'columna_legado' => $clave,
            ]);
        }

        MilestoneCatalog::olvida();
    }

    public static function createUsers(): void
    {
        Schema::create('users', function ($table) {
            $table->increments('usr_id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->tinyInteger('role')->nullable();
            $table->tinyInteger('access')->nullable();
            $table->integer('client_id')->nullable();
            $table->integer('provider_id')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->string('remember_token', 100)->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->dateTime('last_login')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('modified_by')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('modified_at')->nullable();
        });
    }

    public static function create(): void
    {
        // No es del esquema heredado, pero cualquier prueba que pinte una
        // pantalla la necesita: el layout consulta ahí el tema y el idioma del
        // usuario. Hay pruebas que la crean por su cuenta, así que no se repite.
        if (! Schema::hasTable('user_preferences')) {
            Schema::create('user_preferences', function ($table) {
                $table->id();
                $table->unsignedInteger('usr_id')->unique();
                $table->string('theme', 10)->default('system');
                $table->string('locale', 5)->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('account', function ($table) {
            $table->integer('account_id')->primary();
            $table->string('account_name')->nullable();
            $table->integer('default')->nullable();
            $table->string('prefix')->nullable();
        });

        Schema::create('company', function ($table) {
            $table->integer('company_id')->primary();
            $table->string('name');
            $table->string('rfc')->nullable();
            // Datos fiscales del emisor: los usa el layout CFDI.
            $table->string('business_name')->nullable();
            $table->string('regimen_fiscal')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('address')->nullable();
            $table->boolean('active')->default(true);
        });

        Schema::create('booking', function ($table) {
            $table->integer('booking_id')->primary();
            $table->string('booking_number')->nullable();
            $table->integer('client')->nullable();
            $table->date('loading_EDT')->nullable();
            $table->integer('mode')->default(10);
            // Columnas que usa el listado de operación y el portal.
            $table->integer('is_draft')->default(0);
            $table->boolean('locked')->default(false);
            $table->string('customer_reference')->nullable();
            $table->string('commodity')->nullable();
            $table->string('set_point')->nullable();
            // Entero como en producción: 1 = importación, 2 = exportación.
            $table->integer('booking_type')->nullable();
            $table->date('dicharge_ETA')->nullable();
            // La lista de verificación del booking: fecha y hora, como en
            // producción (`Booking::LISTA_DE_VERIFICACION`).
            foreach (['arrival', 'realeased_from_shiping', 'customs_cleared', 'truck_service_request', 'delivered_consigned'] as $paso) {
                $table->dateTime($paso)->nullable();
            }
            $table->integer('vessel')->nullable();
            $table->integer('loading_port')->nullable();
            $table->integer('dicharge_port_id')->nullable();
            $table->integer('pick_up_place_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('modified_by')->nullable();
            $table->string('HB')->nullable();
            $table->integer('carrier_id')->nullable();
            $table->integer('transport_id')->nullable();
            $table->integer('custom_brocker_id')->nullable();
            $table->integer('final_destination_id')->nullable();
            $table->integer('container_type')->nullable();
            // Columnas de texto anteriores a los catálogos; el correo de
            // confirmación las sigue imprimiendo tal cual.
            $table->string('dicharge_port')->nullable();
            $table->string('final_destination')->nullable();
            // Instrucciones de embarque: cada parte, como viene y como debe decir.
            foreach (['shipper', 'consignee', 'notify_party', 'description'] as $parte) {
                $table->text($parte.'_is')->nullable();
                $table->text($parte.'_should')->nullable();
            }
            $table->text('remarks')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('modified_at')->nullable();
            $table->unsignedInteger('operador_id')->nullable();
            $table->unsignedInteger('unidad_id')->nullable();
            $table->unsignedInteger('caja_id')->nullable();
        });

        // Bitácoras. Las escribe la propia base en producción (no la aplicación),
        // así que aquí solo hace falta poder leerlas.
        Schema::create('booking_history', function ($table) {
            $table->string('change_type')->nullable();
            $table->dateTime('change_date')->nullable();
            $table->integer('booking_id')->nullable();
            $table->string('booking_number')->nullable();
            $table->integer('client')->nullable();
            $table->integer('vessel')->nullable();
            $table->integer('loading_port')->nullable();
            $table->integer('carrier_id')->nullable();
            $table->string('commodity')->nullable();
            $table->text('remarks')->nullable();
            $table->integer('modified_by')->nullable();
        });

        Schema::create('containers_history', function ($table) {
            $table->string('change_type')->nullable();
            $table->dateTime('change_date')->nullable();
            $table->integer('container_ID')->nullable();
            $table->integer('booking')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('comodity')->nullable();
            $table->string('number')->nullable();
            $table->string('seal')->nullable();
            $table->integer('container_type')->nullable();
            $table->integer('modified_by')->nullable();
        });

        Schema::create('booking_continuity_history', function ($table) {
            $table->string('change_type')->nullable();
            $table->dateTime('change_date')->nullable();
            $table->integer('cont_id')->nullable();
            $table->integer('booking')->nullable();
            $table->dateTime('pickup_date')->nullable();
            $table->dateTime('SI_date')->nullable();
            $table->dateTime('departure')->nullable();
            $table->integer('modified_by')->nullable();
        });

        Schema::create('check_list_history', function ($table) {
            $table->string('change_type')->nullable();
            $table->dateTime('change_date')->nullable();
            $table->integer('check_id')->nullable();
            $table->integer('booking')->nullable();
            $table->integer('modified_by')->nullable();

            foreach (['booking_number', 'departure', 'delivered'] as $casilla) {
                $table->dateTime($casilla.'_chk_date')->nullable();
                $table->integer($casilla.'_chk_by')->nullable();
            }
        });

        // Tablas que acompañan al booking en el listado de operación.
        Schema::create('operador', function ($table) {
            $table->increments('operador_id');
            $table->string('nombre', 120);
            $table->string('numero', 30)->nullable();
            $table->string('rfc', 20)->nullable();
            $table->string('curp', 20)->nullable();
            $table->string('nss', 20)->nullable();
            $table->string('telefono', 40)->nullable();
            $table->string('licencia', 40)->nullable();
            $table->string('licencia_tipo', 20)->nullable();
            $table->date('licencia_vence')->nullable();
            $table->date('examen_medico_vence')->nullable();
            $table->date('ingreso')->nullable();
            $table->boolean('activo')->default(true);
            $table->string('notas', 255)->nullable();
            $table->string('tarifa_tipo', 12)->nullable();
            $table->decimal('tarifa_valor', 12, 4)->nullable();
        });

        Schema::create('liquidacion', function ($table) {
            $table->increments('liquidacion_id');
            $table->string('numero', 20);
            $table->unsignedInteger('operador_id');
            $table->date('desde');
            $table->date('hasta');
            $table->string('estado', 10)->default('abierta');
            $table->dateTime('pagada_en')->nullable();
            $table->unsignedInteger('bank_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->string('notas', 255)->nullable();
        });

        Schema::create('liquidacion_renglon', function ($table) {
            $table->increments('renglon_id');
            $table->unsignedInteger('liquidacion_id');
            $table->unsignedInteger('booking')->nullable();
            $table->string('concepto', 120);
            $table->string('tipo', 12)->default('percepcion');
            $table->decimal('importe', 16, 4)->default(0);
            $table->date('fecha')->nullable();
        });

        Schema::create('unidad', function ($table) {
            $table->increments('unidad_id');
            $table->string('numero', 30);
            $table->string('tipo', 20)->default('tractor');
            $table->string('placas', 20)->nullable();
            $table->string('marca', 40)->nullable();
            $table->string('modelo', 40)->nullable();
            $table->string('anio', 4)->nullable();
            $table->string('serie', 40)->nullable();
            $table->string('permiso_sct', 40)->nullable();
            $table->date('seguro_vence')->nullable();
            $table->date('verificacion_vence')->nullable();
            $table->unsignedInteger('kilometraje')->nullable();
            $table->unsignedInteger('servicio_cada_km')->nullable();
            $table->unsignedInteger('ultimo_servicio_km')->nullable();
            $table->date('ultimo_servicio')->nullable();
            $table->boolean('activo')->default(true);
            $table->string('notas', 255)->nullable();
        });

        Schema::create('gasto_viaje', function ($table) {
            $table->increments('gasto_id');
            $table->unsignedInteger('booking')->nullable();
            $table->string('tipo', 15)->default('combustible');
            $table->date('fecha');
            $table->unsignedInteger('unidad_id')->nullable();
            $table->unsignedInteger('operador_id')->nullable();
            $table->unsignedInteger('provider_id')->nullable();
            $table->string('descripcion', 120)->nullable();
            $table->decimal('litros', 10, 2)->nullable();
            $table->decimal('precio_litro', 10, 4)->nullable();
            $table->unsignedInteger('odometro')->nullable();
            $table->decimal('importe', 16, 4)->default(0);
            $table->string('forma_pago', 20)->nullable();
            $table->string('folio', 40)->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('refaccion', function ($table) {
            $table->increments('refaccion_id');
            $table->string('codigo', 40);
            $table->string('nombre', 120);
            $table->string('categoria', 40)->nullable();
            $table->string('medida', 20)->nullable();
            $table->string('ubicacion', 40)->nullable();
            $table->decimal('existencia', 12, 2)->default(0);
            $table->decimal('minimo', 12, 2)->default(0);
            $table->decimal('costo', 12, 4)->default(0);
            $table->boolean('activo')->default(true);
            $table->string('notas', 255)->nullable();
        });

        Schema::create('mantenimiento', function ($table) {
            $table->increments('mantenimiento_id');
            $table->string('folio', 20);
            $table->unsignedInteger('unidad_id');
            $table->string('tipo', 12)->default('preventivo');
            $table->string('estado', 10)->default('abierto');
            $table->date('entrada');
            $table->date('salida')->nullable();
            $table->unsignedInteger('odometro')->nullable();
            $table->string('taller', 10)->default('interno');
            $table->unsignedInteger('provider_id')->nullable();
            $table->string('descripcion', 200);
            $table->decimal('mano_obra', 14, 2)->default(0);
            $table->string('notas', 255)->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('mantenimiento_refaccion', function ($table) {
            $table->increments('renglon_id');
            $table->unsignedInteger('mantenimiento_id');
            $table->unsignedInteger('refaccion_id');
            $table->decimal('cantidad', 12, 2)->default(1);
            $table->decimal('costo', 12, 4)->default(0);
        });

        Schema::create('movimiento_refaccion', function ($table) {
            $table->increments('movimiento_id');
            $table->unsignedInteger('refaccion_id');
            $table->string('tipo', 10);
            $table->decimal('cantidad', 12, 2);
            $table->decimal('costo', 12, 4)->default(0);
            $table->date('fecha');
            $table->unsignedInteger('mantenimiento_id')->nullable();
            $table->unsignedInteger('provider_id')->nullable();
            $table->string('folio', 40)->nullable();
            $table->string('notas', 255)->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('empleado', function ($table) {
            $table->increments('empleado_id');
            $table->string('nombre', 120);
            $table->string('numero', 30)->nullable();
            $table->string('puesto', 60)->nullable();
            $table->string('departamento', 60)->nullable();
            $table->string('rfc', 20)->nullable();
            $table->string('curp', 20)->nullable();
            $table->string('nss', 20)->nullable();
            $table->date('ingreso')->nullable();
            $table->decimal('salario_diario', 12, 4)->nullable();
            $table->string('banco', 40)->nullable();
            $table->string('clabe', 20)->nullable();
            $table->unsignedInteger('operador_id')->nullable();
            $table->boolean('activo')->default(true);
            $table->string('notas', 255)->nullable();
        });

        Schema::create('nomina', function ($table) {
            $table->increments('nomina_id');
            $table->string('numero', 20);
            $table->date('desde');
            $table->date('hasta');
            $table->string('periodicidad', 12)->default('quincenal');
            $table->string('estado', 10)->default('abierta');
            $table->dateTime('pagada_en')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->string('notas', 255)->nullable();
        });

        Schema::create('nomina_renglon', function ($table) {
            $table->increments('renglon_id');
            $table->unsignedInteger('nomina_id');
            $table->unsignedInteger('empleado_id');
            $table->string('concepto', 120);
            $table->string('tipo', 12)->default('percepcion');
            $table->decimal('importe', 16, 4)->default(0);
            $table->unsignedInteger('liquidacion_id')->nullable();
        });

        // Bitácora de cancelaciones de CFDI (migración `create_cfdi_cancelacion`).
        Schema::create('cfdi_cancelacion', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('transc_id')->unique();
            $table->string('uuid', 40);
            $table->string('motivo', 2);
            $table->string('sustituye', 40)->nullable();
            $table->string('estado', 12)->default('solicitada');
            $table->string('codigo', 20)->nullable();
            $table->string('mensaje', 255)->nullable();
            $table->string('sat_estado', 20)->nullable();
            $table->string('sat_estatus', 40)->nullable();
            $table->unsignedInteger('solicitado_por')->nullable();
            $table->dateTime('solicitado_at');
            $table->dateTime('verificado_at')->nullable();
            $table->index('estado');
        });

        Schema::create('configuracion', function ($table) {
            $table->string('clave', 60)->primary();
            $table->text('valor')->nullable();
            $table->dateTime('modified_at')->nullable();
            $table->unsignedInteger('modified_by')->nullable();
        });

        Schema::create('solicitud_demo', function ($table) {
            $table->increments('solicitud_id');
            $table->string('nombre', 100);
            $table->string('empresa', 100)->nullable();
            $table->string('correo', 120);
            $table->string('telefono', 40)->nullable();
            $table->string('mensaje', 500)->nullable();
            $table->string('origen', 60)->nullable();
            $table->boolean('atendida')->default(false);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('campo_expediente', function ($table) {
            $table->increments('campo_id');
            $table->string('clave', 40);
            $table->string('etiqueta', 60);
            $table->string('tipo', 10)->default('text');
            $table->string('opciones', 255)->nullable();
            $table->string('grupo', 40)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('obligatorio')->default(false);
            $table->boolean('activo')->default(true);
        });

        Schema::create('valor_por_expediente', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('booking');
            $table->unsignedInteger('campo_id');
            $table->string('valor', 255)->nullable();
            $table->unique(['booking', 'campo_id']);
        });

        Schema::create('hito', function ($table) {
            $table->increments('hito_id');
            $table->string('clave', 40);
            $table->string('etiqueta', 60);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->string('columna_legado', 40)->nullable();
        });

        Schema::create('hito_por_expediente', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('booking');
            $table->unsignedInteger('hito_id');
            $table->dateTime('fecha')->nullable();
            $table->unsignedInteger('modified_by')->nullable();
            $table->dateTime('modified_at')->nullable();
            $table->unique(['booking', 'hito_id']);
        });

        // La modalidad del embarque (CY/CY, SD/SD…), que se elige en el detalle.
        Schema::create('modality', function ($table) {
            $table->increments('modality_id');
            $table->string('modality_name', 15)->nullable();
        });

        Schema::create('booking_continuity', function ($table) {
            $table->increments('cont_id');
            $table->integer('booking')->nullable();
            $table->integer('modality')->nullable();
            $table->string('vacuum_maneuver')->nullable();
            $table->integer('modified_by')->nullable();
            $table->dateTime('modified_at')->nullable();

            // Un campo por hito de la continuidad.
            foreach ([
                'pickup_date', 'doc_cut_of', 'SI_date', 'draf_client', 'corrected_draft', 'vgm',
                'gated_IN', 'cleared', 'departure', 'bl_payment', 'swb', 'delivered',
                'gated_out', 'insurance',
            ] as $hito) {
                $table->dateTime($hito)->nullable();
            }
        });

        Schema::create('vessel', function ($table) {
            $table->integer('vessel_id')->primary();
            $table->string('vessel_name')->nullable();
        });

        Schema::create('loading_ports', function ($table) {
            $table->integer('port_id')->primary();
            $table->string('port_name')->nullable();
            $table->integer('deleted')->default(0);
            $table->decimal('latitud', 9, 6)->nullable();
            $table->decimal('longitud', 9, 6)->nullable();
        });

        Schema::create('dicharge_port', function ($table) {
            $table->integer('dicharge_port_id')->primary();
            $table->string('name')->nullable();
            $table->integer('deleted')->default(0);
            $table->decimal('latitud', 9, 6)->nullable();
            $table->decimal('longitud', 9, 6)->nullable();
        });

        Schema::create('pickup_place', function ($table) {
            $table->integer('pick_id')->primary();
            $table->string('name')->nullable();
            // La dirección completa la imprime la confirmación del booking.
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('postal_code')->nullable();
            $table->decimal('latitud', 9, 6)->nullable();
            $table->decimal('longitud', 9, 6)->nullable();
        });

        Schema::create('final_destination', function ($table) {
            $table->integer('final_destination_id')->primary();
            $table->string('name')->nullable();
            $table->integer('deleted')->default(0);
            $table->decimal('latitud', 9, 6)->nullable();
            $table->decimal('longitud', 9, 6)->nullable();
        });

        // Cumplimiento de la lista de verificación: por casilla, cuándo se marcó
        // y quién. `modified_by` es el autor que lee el disparador de
        // `check_list_history` en producción.
        Schema::create('check_list', function ($table) {
            $table->increments('check_id');
            $table->integer('booking')->nullable();
            $table->integer('modified_by')->nullable();

            foreach ([
                'booking_number', 'pickup_date', 'modality', 'doc_cut_of', 'SI_date', 'cleared',
                'departure', 'bl_payment', 'swb', 'vessel', 'number', 'client', 'loading_port',
                'loading_EDT', 'dicharge_port', 'container_type', 'commodity', 'set_point',
                'dicharge_ETA', 'vacuum_maneuver', 'draf_client', 'gated_IN', 'gated_out',
                'delivered', 'pick_up_place', 'insurance', 'corrected_draft', 'vgm',
            ] as $casilla) {
                $table->dateTime($casilla.'_chk_date')->nullable();
                $table->integer($casilla.'_chk_by')->nullable();
            }
        });

        // Documentos que se adjuntan a un booking: qué campos pide cada cliente
        // (`fields_by_client` sobre el catálogo `file_fields`) y qué se subió.
        Schema::create('file_fields', function ($table) {
            $table->increments('field_id');
            $table->string('field')->nullable();
            $table->string('label')->nullable();
            $table->boolean('default')->default(false);
        });

        Schema::create('fields_by_client', function ($table) {
            $table->increments('customer_field_id');
            $table->integer('client_id')->nullable();
            $table->integer('field_id')->nullable();
        });

        Schema::create('files_by_booking', function ($table) {
            $table->increments('booking_file_id');
            $table->integer('booking_id')->nullable();
            $table->integer('field_id')->nullable();
            $table->text('value')->nullable();
        });

        // En producción `booking`, `container_type`, `quantity`, `comodity` y
        // las cuatro columnas de auditoría son NOT NULL **sin default**: un NULL
        // explícito falla allá y tiene que fallar aquí. El default de las de
        // auditoría y de `comodity` no existe en producción; está solo para
        // las pruebas que dan de alta contenedores sin capturarlas.
        Schema::create('containers', function ($table) {
            $table->increments('container_ID');
            $table->integer('booking');
            $table->integer('container_type');
            $table->integer('quantity');
            $table->string('comodity', 25)->default('');
            $table->string('number')->nullable();
            $table->string('seal')->nullable();
            $table->dateTime('pick_up_date')->nullable();
            $table->integer('created_by')->default(0);
            $table->integer('modified_by')->default(0);
            $table->date('created_at')->default('1970-01-01');
            $table->date('modified_at')->default('1970-01-01');
        });

        Schema::create('container_types', function ($table) {
            $table->integer('contType_id')->primary();
            $table->string('container_name')->nullable();
        });

        // Las longitudes son las de la base real (SQLite no las hace valer, pero
        // documentan lo que la ficha tiene que validar). `phone` es `int(11)`.
        Schema::create('client', function ($table) {
            $table->integer('client_id')->primary();
            $table->string('fullName', 100);
            // En producción es NOT NULL: los clientes sin correo lo tienen vacío.
            // El default es solo para no repetirlo en cada alta de prueba.
            $table->string('email', 50)->default('');
            $table->string('email_notification', 1000)->nullable();
            $table->text('notification_notes')->nullable();
            $table->string('country', 25)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('modified_at')->nullable();
            // Datos fiscales del receptor: los usa el layout CFDI.
            $table->string('rfc', 25)->nullable();
            $table->string('pay_form', 5)->nullable();
            $table->string('pay_method', 5)->nullable();
            $table->string('invoice_use', 5)->nullable();
            $table->string('regimen_fiscal_id', 3)->nullable();
            $table->string('postal_code', 25)->nullable();
            $table->integer('phone')->nullable();
            $table->string('address', 255)->nullable();
            $table->string('address2', 255)->nullable();
            $table->string('city', 25)->nullable();
            $table->string('state', 25)->nullable();
            $table->integer('account_id')->nullable();
            $table->integer('match_pickup_place')->default(0);
            $table->integer('created_by')->nullable();
            $table->integer('modified_by')->nullable();
        });

        Schema::create('provider', function ($table) {
            $table->integer('provider_id')->primary();
            $table->string('fullName', 100)->nullable();
            $table->string('email', 50)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('modified_at')->nullable();
            // 1 naviera, 2 transportista, 3 agente aduanal.
            $table->integer('type_id')->nullable();
            $table->string('rfc', 25)->nullable();
            $table->integer('phone')->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 25)->nullable();
            $table->string('state', 25)->nullable();
            $table->string('postal_code', 25)->nullable();
            $table->integer('account_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('modified_by')->nullable();
        });

        // Catálogos del SAT para los datos fiscales del cliente. En la base real
        // no tienen llave primaria; `code` es lo que se guarda en `client`.
        foreach (['invoice_use', 'pay_method', 'pay_form'] as $catalogoSat) {
            Schema::create($catalogoSat, function ($table) {
                $table->string('code', 4)->nullable();
                $table->string('name', 64)->nullable();
            });
        }

        Schema::create('charge_type', function ($table) {
            $table->integer('charge_type_id')->primary();
            $table->string('charge_type_name')->nullable();
            $table->string('tax_name')->nullable();
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_retention', 7, 4)->default(0);
            $table->string('product_code')->nullable();
            $table->boolean('non_deductible')->default(false);
            $table->boolean('deleted')->default(false);
        });

        Schema::create('service', function ($table) {
            $table->integer('service_id')->primary();
            $table->integer('charge_type_id')->nullable();
            $table->integer('client_id')->nullable();
            $table->integer('provider_id')->nullable();
            $table->integer('type')->nullable();
            $table->decimal('price', 16, 4)->nullable();
            $table->string('description')->nullable();
            $table->integer('active')->default(1);
            $table->integer('created_by')->nullable();
            $table->integer('modified_by')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('modified_at')->nullable();
            // Columnas con las que el booking genera solo su factura y sus costos:
            // la ruta que tiene que empatar, cómo se cobra el precio y su vigencia.
            $table->integer('account_id')->nullable();
            $table->integer('auto_include')->nullable();
            $table->integer('price_type')->nullable();
            $table->integer('loading_port_id')->nullable();
            $table->integer('dicharge_port_id')->nullable();
            $table->integer('pickup_place_id')->nullable();
            $table->integer('final_destination_id')->nullable();
            $table->integer('container_type_id')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('min', 16, 4)->nullable();
            $table->decimal('max', 16, 4)->nullable();
            // Nombre del contrato en PDF (`uploads/services/{id}/pdf/`).
            $table->string('contract', 255)->nullable();
        });

        Schema::create('exchange', function ($table) {
            $table->integer('exchange_id')->primary();
            $table->decimal('exchange_value', 11, 4);
            $table->date('date_exchange');
            $table->integer('account')->nullable();
            $table->date('taken_date')->nullable();
            $table->text('url')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('modified_by')->nullable();
            // En la base son `date`, no `datetime`.
            $table->date('created_at')->nullable();
            $table->date('modified_at')->nullable();
        });

        Schema::create('transaction', function ($table) {
            $table->integer('transc_id')->primary();
            $table->date('tran_date')->nullable();
            $table->string('tran_number')->nullable();
            $table->integer('account')->nullable();
            $table->integer('company_id')->nullable();
            $table->integer('booking')->nullable();
            $table->integer('vendor')->nullable();
            $table->integer('customer')->nullable();
            $table->integer('tran_type')->nullable();
            // Nullable como en la base real: los costos se guardan sin tipo de
            // factura, y el motor cuenta con que la comparación contra NULL caiga
            // al ELSE del CASE.
            $table->integer('invoice_type')->nullable()->default(1);
            $table->integer('invoice')->nullable();
            $table->string('seal')->nullable();
            $table->string('new_seal')->nullable();
            $table->string('cancel_reason_id')->nullable();
            $table->string('pdf_attach')->default('');
            $table->string('xml_attach')->nullable();
            $table->integer('cancelled')->default(0);
            $table->integer('paid')->default(0);
            $table->integer('payment_request')->default(0);
            $table->integer('processed')->nullable();
            $table->integer('request_id')->nullable();
            $table->integer('bank_id')->nullable();
            $table->integer('payment_terms')->nullable();
            $table->decimal('paid_amount', 18, 4)->nullable();
            $table->decimal('custom_tc', 10, 4)->nullable();
            $table->integer('open')->default(1);
            $table->integer('active')->default(1);
            $table->dateTime('request_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('modified_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('modified_by')->nullable();
        });

        Schema::create('charge', function ($table) {
            $table->integer('charge_id')->primary();
            $table->integer('transaction')->nullable();
            $table->integer('service_id')->nullable();
            $table->integer('type')->nullable();
            $table->string('tax_code')->nullable();
            $table->string('description')->nullable();
            $table->decimal('quantity', 16, 4)->nullable();
            $table->decimal('unit', 16, 4)->nullable();
            $table->decimal('price', 16, 4)->nullable();
            $table->integer('prepaid')->nullable();
        });

        Schema::create('bank', function ($table) {
            $table->integer('bank_id')->primary();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('default')->default(false);
        });

        Schema::create('payment_request', function ($table) {
            $table->integer('request_id')->primary();
            $table->string('number')->default('');
            $table->decimal('amount', 18, 2)->default(0);
            $table->integer('paid')->default(0);
            $table->integer('provider_id')->nullable();
            $table->integer('client_id')->nullable();
            $table->integer('currency_id')->nullable();
            $table->integer('bank_id')->nullable();
            $table->integer('type')->nullable();
            $table->date('date')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('modified_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('modified_by')->nullable();
            $table->integer('opened')->default(1);
            $table->integer('temp_number')->nullable();
            $table->integer('custom_tc')->nullable();
            $table->decimal('tc_value', 16, 4)->nullable();
            $table->decimal('total_to_pay', 18, 4)->nullable();
            $table->text('payments')->nullable();
        });

        Schema::create('payments_by_transaction', function ($table) {
            $table->integer('request_id');
            $table->integer('transc_id');
            $table->decimal('amount', 16, 4)->nullable();
            $table->date('created_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->dateTime('modified_at')->nullable();
            $table->integer('modified_by')->nullable();
            $table->integer('paid')->default(0);
            $table->primary(['request_id', 'transc_id']);
        });

        // Los hitos van sembrados siempre: desde que dejaron de ser columnas,
        // sin catálogo no hay rejilla de continuidad que pintar ni hito que
        // validar, y media docena de pruebas se caían por eso.
        self::seedHitos();
    }
}
