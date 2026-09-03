<?php

namespace Database\Seeders;

use Database\Seeders\Perfiles\PerfilDemo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Llena una base VACÍA con una operación de ejemplo completa, para poder
 * enseñar el sistema sin datos de ningún cliente real.
 *
 * Todo lo que escribe es inventado: ni un nombre, ni un RFC, ni un importe sale
 * de una base de verdad. Aun así la operación cuadra —cada booking tiene su
 * factura, sus costos, sus contenedores y su lista de verificación— porque un
 * sistema con datos incoherentes no se puede enseñar: los reportes salen en
 * cero y la utilidad por booking no significa nada.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Es determinista (semilla fija): dos corridas producen exactamente los mismos
 * datos, así que una captura de pantalla de hoy sigue valiendo mañana.
 *
 * ⚠️ Se niega a correr contra la base del sistema anterior. Ver `guarda()`.
 */
class DemoSeeder extends Seeder
{
    /** Semilla fija: la gracia es que la demostración no cambie sola. */
    private const SEMILLA = 20260828;

    /** Cuántos embarques se inventan, hacia atrás desde hoy. */
    private const BOOKINGS = 45;

    private PerfilDemo $perfil;

    private string $hoy;

    private string $ahora;

    /** @var array<int, array<string, mixed>> */
    private array $bookings = [];

    public function run(): void
    {
        $this->guarda();

        mt_srand(self::SEMILLA);
        $this->perfil = PerfilDemo::elegido();
        $this->hoy = Carbon::now()->toDateString();
        $this->ahora = Carbon::now()->toDateTimeString();

        $this->limpia();
        $this->catalogos();
        $this->terceros();
        $this->servicios();
        $this->flota();
        $this->usuarios();
        $this->tiposDeCambio();
        $this->embarques();
        $this->facturacion();

        $this->command?->info(
            'Datos de demostración («'.$this->perfil->nombre().'») listos. Entra con '.$this->claveVisible()
        );
    }

    /**
     * Nadie borra por accidente la base del sistema anterior.
     *
     * La copia local con los datos reales es la que usan las pruebas de
     * paridad, y este seeder empieza vaciando tablas: un `.env` mal apuntado
     * la dejaría inservible. Por eso se compara contra la base que declara la
     * conexión `frego_legacy` y se exige que la base actual esté vacía de
     * operación (o que se pida explícitamente sobrescribirla).
     */
    private function guarda(): void
    {
        $actual = (string) DB::connection()->getDatabaseName();
        $heredada = (string) config('database.connections.frego_legacy.database');

        if ($heredada !== '' && $actual === $heredada) {
            throw new RuntimeException(
                "DemoSeeder se niega a escribir en `{$actual}`: es la base del sistema anterior ".
                '(`FREGO_DB_DATABASE`), la que usan las pruebas de paridad. '.
                'Apunta `DB_DATABASE` a la base de demostración.'
            );
        }

        $transacciones = DB::table('transaction')->count();

        if ($transacciones > 0 && ! env('DEMO_SEED_FORCE', false)) {
            throw new RuntimeException(
                "La base `{$actual}` ya tiene {$transacciones} transacciones. ".
                'Si de verdad quieres borrarlas y volver a llenarla, corre con DEMO_SEED_FORCE=true.'
            );
        }
    }

    /** Contraseña de todas las cuentas de ejemplo. */
    private function claveVisible(): string
    {
        return (string) env('DEMO_PASSWORD', 'demo1234');
    }

    /**
     * Vacía lo que este seeder llena. No toca las tablas de Laravel (sesiones,
     * caché, migraciones) ni las bitácoras históricas, que se llenan solas.
     */
    private function limpia(): void
    {
        $tablas = [
            'payments_by_transaction', 'payment_request', 'charge', 'transaction',
            'check_list', 'booking_continuity', 'hito_por_expediente', 'containers', 'gasto_viaje', 'files_by_booking', 'booking',
            'operador', 'unidad',
            'service', 'fields_by_client', 'client', 'provider', 'vessel', 'exchange',
            'users', 'user_preferences', 'account', 'bank', 'carrier', 'charge_type',
            'container_types', 'dicharge_port', 'final_destination', 'loading_ports',
            'modality', 'pickup_place', 'company', 'file_fields', 'invoice_use',
            'pay_form', 'pay_method', 'roles',
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ($tablas as $tabla) {
            DB::table($tabla)->delete();

            // Los identificadores vuelven a empezar en 1 para que la
            // demostración se vea recién instalada y no heredada.
            try {
                DB::statement("ALTER TABLE `{$tabla}` AUTO_INCREMENT = 1");
            } catch (\Throwable) {
                // Las tablas sin autoincremento (catálogos del SAT) no lo tienen.
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function catalogos(): void
    {
        foreach ($this->perfil->companias() as $i => $compania) {
            DB::table('company')->insert([
                'company_id' => $i + 1, 'name' => $compania['nombre'], 'business_name' => $compania['razon'],
                'rfc' => 'XAXX010101000', 'regimen_fiscal' => '601', 'postal_code' => '44100',
                'address' => null, 'active' => 1,
            ]);
        }

        // Divisas. La primera es la de casa (`default`), y el prefijo es el que
        // se enseña en los listados.
        DB::table('account')->insert([
            ['account_id' => 1, 'account_name' => 'Pagos en MXN', 'default' => 1, 'prefix' => 'MXN'],
            ['account_id' => 2, 'account_name' => 'Pagos en USD', 'default' => null, 'prefix' => 'USD'],
            ['account_id' => 3, 'account_name' => 'Pagos en EUR', 'default' => null, 'prefix' => 'EUR'],
        ]);

        foreach ($this->perfil->bancos() as $i => $banco) {
            DB::table('bank')->insert([
                'bank_id' => $i + 1, 'bank_name' => $banco,
                'account_number' => str_pad((string) ($i * 111111), 10, '0', STR_PAD_LEFT),
                'active' => 1, 'default' => $i === 0 ? 1 : null, 'created_at' => $this->ahora,
            ]);
        }

        // Tipos de cargo: de aquí salen el IVA y la retención de cada concepto.
        foreach ($this->perfil->tiposDeCargo() as $i => $tipo) {
            DB::table('charge_type')->insert([
                'charge_type_id' => $i + 1,
                'charge_type_name' => $tipo['nombre'],
                'tax_rate' => $tipo['iva'],
                'tax_retention' => $tipo['retencion'],
                'tax_name' => $tipo['iva'] > 0 ? 'IVA 16' : 'IVA 0',
                'product_code' => '80151600',
                'non_deductible' => $tipo['no_deducible'] ? 1 : 0,
                'deleted' => 0,
            ]);
        }

        foreach ($this->perfil->unidades() as $i => $nombre) {
            DB::table('container_types')->insert(['contType_id' => $i + 1, 'container_name' => $nombre]);
        }

        // Con coordenadas: de ellas sale el mapa de rutas del panel.
        $donde = $this->perfil->coordenadas();
        $punto = fn (string $nombre) => [
            'latitud' => $donde[$nombre][0] ?? null,
            'longitud' => $donde[$nombre][1] ?? null,
        ];

        foreach ($this->perfil->origenes() as $i => $nombre) {
            DB::table('loading_ports')->insert(
                ['port_id' => $i + 1, 'port_name' => $nombre, 'deleted' => 0] + $punto($nombre)
            );
        }

        foreach ($this->perfil->destinos() as $i => $nombre) {
            DB::table('dicharge_port')->insert(
                ['dicharge_port_id' => $i + 1, 'name' => $nombre, 'deleted' => 0] + $punto($nombre)
            );
            DB::table('final_destination')->insert(
                ['final_destination_id' => $i + 1, 'name' => $nombre, 'deleted' => 0] + $punto($nombre)
            );
        }

        foreach ($this->perfil->modalidades() as $i => $nombre) {
            DB::table('modality')->insert(['modality_id' => $i + 1, 'modality_name' => $nombre]);
        }

        foreach ($this->perfil->lugaresRecoleccion() as $i => $nombre) {
            DB::table('pickup_place')->insert([
                'pick_id' => $i + 1, 'name' => $nombre, 'address1' => 'Av. Demostración '.(100 + $i * 10),
                'city' => 'Ciudad Demo', 'state' => 'Estado Demo', 'country' => 'México',
                'postal_code' => '4'.str_pad((string) ($i * 7 + 100), 4, '0', STR_PAD_LEFT),
                'created_at' => $this->hoy, 'created_by' => 1,
            ] + $punto($nombre));
        }

        // Documentos que se le piden al cliente en cada expediente.
        $orden = 0;

        foreach ($this->perfil->documentos() as $campo => $etiqueta) {
            $orden++;
            DB::table('file_fields')->insert([
                'field_id' => $orden, 'field' => $campo, 'label' => $etiqueta, 'default' => 1,
            ]);
        }

        // Catálogos del SAT: son públicos y valen para cualquier instalación.
        DB::table('pay_method')->insert([
            ['code' => 'PUE', 'name' => 'Pago en una sola exhibición', 'description' => null],
            ['code' => 'PPD', 'name' => 'Pago en parcialidades o diferido', 'description' => null],
        ]);

        DB::table('pay_form')->insert([
            ['code' => '01', 'name' => 'Efectivo', 'description' => null],
            ['code' => '03', 'name' => 'Transferencia electrónica de fondos', 'description' => null],
            ['code' => '04', 'name' => 'Tarjeta de crédito', 'description' => null],
            ['code' => '99', 'name' => 'Por definir', 'description' => null],
        ]);

        DB::table('invoice_use')->insert([
            ['code' => 'G01', 'name' => 'Adquisición de mercancías', 'fisica' => 'Sí', 'moral' => 'Sí'],
            ['code' => 'G03', 'name' => 'Gastos en general', 'fisica' => 'Sí', 'moral' => 'Sí'],
            ['code' => 'S01', 'name' => 'Sin efectos fiscales', 'fisica' => 'Sí', 'moral' => 'Sí'],
        ]);

        foreach (['super-admin', 'admin', 'operaciones', 'facturacion', 'pagos', 'cliente', 'proveedor'] as $i => $rol) {
            DB::table('roles')->insert([
                'id' => $i + 1, 'name' => $rol, 'guard_name' => 'web',
                'created_at' => $this->ahora, 'updated_at' => $this->ahora,
            ]);
        }

        foreach (range(1, 24) as $i) {
            DB::table('vessel')->insert([
                'vessel_id' => $i,
                'vessel_name' => $this->perfil->prefijoMedio().str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }
    }

    private function terceros(): void
    {
        foreach ($this->perfil->clientes() as $i => $nombre) {
            DB::table('client')->insert([
                'client_id' => $i + 1,
                'fullName' => $nombre.', S.A. de C.V.',
                'rfc' => 'XAXX010101000',
                'account_id' => $i % 2 === 0 ? 2 : 1,
                'email' => 'contacto'.($i + 1).'@cliente-demo.test',
                'email_notification' => 'operaciones'.($i + 1).'@cliente-demo.test',
                'address' => 'Av. Demostración '.(100 + $i * 10),
                'colony' => 'Zona Industrial',
                'city' => 'Ciudad Demo',
                'state' => 'Estado Demo',
                'postal_code' => '4'.str_pad((string) ($i * 7 + 100), 4, '0', STR_PAD_LEFT),
                'country' => 'México',
                'pay_method' => 'PUE',
                'pay_form' => '03',
                'invoice_use' => 'G03',
                'regimen_fiscal_id' => '601',
                'created_at' => $this->ahora,
                'created_by' => 1,
                'role' => 16,
            ]);

            // Qué papeles se le piden a cada cliente. Sin esto, un cliente nuevo
            // no tiene dónde recibir documentos.
            foreach ([1, 2, 6, 7, 8] as $campo) {
                DB::table('fields_by_client')->insert(['client_id' => $i + 1, 'field_id' => $campo]);
            }
        }

        // type_id: los tres selectores de proveedor del expediente.
        foreach ($this->perfil->proveedores() as $i => ['nombre' => $nombre, 'tipo' => $tipo]) {
            DB::table('provider')->insert([
                'provider_id' => $i + 1,
                'fullName' => $nombre.', S.A. de C.V.',
                'rfc' => 'XAXX010101000',
                'account_id' => $tipo === 1 ? 2 : 1,
                'email' => 'cuentas'.($i + 1).'@proveedor-demo.test',
                'address' => 'Calle Proveedor '.(10 + $i),
                'city' => 'Ciudad Demo',
                'state' => 'Estado Demo',
                'postal_code' => '45'.str_pad((string) ($i * 3), 3, '0', STR_PAD_LEFT),
                'type_id' => $tipo,
                'verification_code' => '',
                'created_at' => $this->ahora,
                'created_by' => 1,
            ]);
        }
    }

    /**
     * Catálogo de precios. De aquí sale la generación automática de la factura
     * y de los costos del booking, así que hay que cubrir las combinaciones que
     * usan los embarques inventados: por eso se recorre puerto por puerto.
     */
    private function servicios(): void
    {
        $id = 0;
        $inserta = function (array $fila) use (&$id) {
            $id++;
            DB::table('service')->insert(array_merge([
                'service_id' => $id,
                'active' => 1,
                'created_at' => $this->ahora,
                'created_by' => 1,
                'start_date' => Carbon::now()->subYear()->toDateString(),
                'end_date' => Carbon::now()->addYear()->toDateString(),
                'auto_include' => 1,
                'price_type' => 1,
                'min' => null,
                'max' => null,
            ], $fila));
        };

        $c = $this->perfil->conceptos();

        foreach (range(1, 4) as $puerto) {
            foreach (range(1, 6) as $destino) {
                // Lo que se le cobra al cliente por esa ruta.
                $inserta([
                    'name' => $c['venta_principal']['nombre'],
                    'description' => $c['venta_principal']['descripcion'],
                    'price' => 1800 + ($puerto * 40) + ($destino * 65),
                    'account_id' => 2,
                    'charge_type_id' => $c['venta_principal']['cargo'],
                    'type' => 0,
                    'loading_port_id' => $puerto,
                    'dicharge_port_id' => $destino,
                ]);

                // Lo que cuesta: lo que cobra el proveedor principal.
                $inserta([
                    'name' => $c['costo_principal']['nombre'],
                    'description' => $c['costo_principal']['descripcion'],
                    'price' => 1450 + ($puerto * 35) + ($destino * 50),
                    'account_id' => 2,
                    'charge_type_id' => $c['costo_principal']['cargo'],
                    'type' => 1,
                    'provider_id' => 1 + (($puerto + $destino) % 3),
                    'loading_port_id' => $puerto,
                    'dicharge_port_id' => $destino,
                ]);
            }

            // El acarreo, por el proveedor del segundo tipo.
            foreach (range(1, 4) as $recoleccion) {
                $inserta([
                    'name' => $c['acarreo']['nombre'],
                    'description' => $c['acarreo']['descripcion'],
                    'price' => 12000 + ($puerto * 500) + ($recoleccion * 350),
                    'account_id' => 1,
                    'charge_type_id' => $c['acarreo']['cargo'],
                    'type' => 1,
                    'provider_id' => 4 + (($puerto + $recoleccion) % 4),
                    'loading_port_id' => $puerto,
                    'pickup_place_id' => $recoleccion,
                ]);
            }

            $inserta([
                'name' => $c['tramite']['nombre'],
                'description' => $c['tramite']['descripcion'],
                'price' => 6500 + ($puerto * 250),
                'account_id' => 1,
                'charge_type_id' => $c['tramite']['cargo'],
                'type' => 1,
                'provider_id' => 8 + ($puerto % 3),
                'loading_port_id' => $puerto,
            ]);

            $inserta([
                'name' => $c['maniobras']['nombre'],
                'description' => $c['maniobras']['descripcion'],
                'price' => 4200 + ($puerto * 180),
                'account_id' => 1,
                'charge_type_id' => $c['maniobras']['cargo'],
                'type' => 0,
                'loading_port_id' => $puerto,
            ]);
        }

        // Servicios sueltos, sin ruta: se ofrecen en cualquier documento.
        foreach ($c['sueltos'] as ['nombre' => $nombre, 'cargo' => $tipoCargo, 'tipo' => $tipo, 'precio' => $precio, 'divisa' => $divisa]) {
            $inserta([
                'name' => $nombre,
                'description' => $nombre,
                'price' => $precio,
                'account_id' => $divisa,
                'charge_type_id' => $tipoCargo,
                'type' => $tipo,
                'auto_include' => 0,
            ]);
        }
    }

    /**
     * Operadores y unidades. Solo el perfil de autotransporte los trae: quien
     * subcontrata el transporte no tiene flota que sembrar.
     */
    private function flota(): void
    {
        foreach ($this->perfil->operadores() as $i => $operador) {
            DB::table('operador')->insert([
                'operador_id' => $i + 1,
                'nombre' => $operador['nombre'],
                'numero' => 'OP-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'licencia' => $operador['licencia'],
                'licencia_tipo' => 'E',
                'licencia_vence' => Carbon::parse($operador['vence'])->toDateString(),
                'examen_medico_vence' => Carbon::parse($operador['vence'])->addMonths(3)->toDateString(),
                'telefono' => '81 1234 '.str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT),
                'ingreso' => Carbon::now()->subYears(2)->addMonths($i)->toDateString(),
                // Con tarifa capturada: sin ella, la liquidación propondría cero
                // y la demostración no enseñaría nada.
                'tarifa_tipo' => $i % 3 === 0 ? 'porcentaje' : 'fijo',
                'tarifa_valor' => $i % 3 === 0 ? 12 : 3200 + $i * 150,
                'activo' => 1,
            ]);
        }

        foreach ($this->perfil->unidadesFlota() as $i => $unidad) {
            DB::table('unidad')->insert([
                'unidad_id' => $i + 1,
                'numero' => $unidad['numero'],
                'tipo' => $unidad['tipo'],
                'placas' => $unidad['placas'],
                'marca' => $unidad['marca'],
                'anio' => $unidad['anio'],
                'seguro_vence' => Carbon::parse($unidad['seguro'])->toDateString(),
                'verificacion_vence' => Carbon::parse($unidad['seguro'])->addMonths(2)->toDateString(),
                'kilometraje' => 180000 + $i * 42000,
                'activo' => 1,
            ]);
        }
    }

    private function usuarios(): void
    {
        $clave = Hash::make($this->claveVisible());

        $cuentas = [
            // Interna: rol 20 super admin, 10 admin, 9 usuario normal.
            ['demo.admin', 'Ana Demo (super administrador)', 20, 9, null, null],
            ['demo.facturacion', 'Beatriz Demo (facturación)', 10, 9, null, null],
            ['demo.operaciones', 'Carlos Demo (operación)', 9, 9, null, null],
            // Portal: access 10 cliente, 11 proveedor.
            ['demo.cliente', $this->perfil->clientes()[0], 16, 10, 1, null],
            ['demo.proveedor', $this->perfil->proveedores()[0]['nombre'], 15, 11, null, 1],
        ];

        foreach ($cuentas as $i => [$usuario, $nombre, $rol, $acceso, $cliente, $proveedor]) {
            DB::table('users')->insert([
                'usr_id' => $i + 1,
                'username' => $usuario,
                'name' => $nombre,
                'email' => $usuario.'@cargosuite.test',
                'password' => $clave,
                'role' => $rol,
                'access' => $acceso,
                'client_id' => $cliente,
                'provider_id' => $proveedor,
                'status' => 1,
                'created_at' => $this->ahora,
                'created_by' => 1,
            ]);
        }
    }

    /**
     * Un tipo de cambio por día hábil para dólar y euro, un año hacia atrás.
     *
     * Hace falta más de lo que parece: los reportes de pagos revalúan a una
     * fecha elegida y sin cotización de ese día salen en cero.
     */
    private function tiposDeCambio(): void
    {
        $fecha = Carbon::now()->subYear()->startOfDay();
        $fin = Carbon::now();
        $dolar = 17.35;
        $euro = 18.90;
        $id = 0;

        while ($fecha->lte($fin)) {
            if (! $fecha->isWeekend()) {
                // Camina despacio y sin rumbo, como una cotización de verdad.
                $dolar = round(max(15.5, min(21.0, $dolar + (mt_rand(-9, 9) / 100))), 4);
                $euro = round(max(17.0, min(23.0, $euro + (mt_rand(-11, 11) / 100))), 4);

                foreach ([2 => $dolar, 3 => $euro] as $divisa => $valor) {
                    $id++;
                    DB::table('exchange')->insert([
                        'exchange_id' => $id,
                        'account' => $divisa,
                        'exchange_value' => $valor,
                        'date_exchange' => $fecha->toDateString(),
                        'taken_date' => $fecha->copy()->subDay()->toDateString(),
                        'created_at' => $fecha->toDateString(),
                        'created_by' => 1,
                    ]);
                }
            }

            $fecha->addDay();
        }
    }

    private function embarques(): void
    {
        $trabajos = $this->perfil->trabajos();
        $operadores = count($this->perfil->operadores());
        $tractores = count(array_filter($this->perfil->unidadesFlota(), fn ($u) => $u['tipo'] === 'tractor'));
        $cajas = count($this->perfil->unidadesFlota()) - $tractores;

        for ($n = 1; $n <= self::BOOKINGS; $n++) {
            // Repartidos a lo largo de diez meses, los más nuevos al final.
            $carga = Carbon::now()->subDays((int) round((self::BOOKINGS - $n) * 6.6) + mt_rand(0, 4));
            $creado = $carga->copy()->subDays(mt_rand(10, 25));
            $arribo = $carga->copy()->addDays(mt_rand(18, 34));

            $puerto = 1 + ($n % 4);
            $destino = 1 + ($n % 6);
            $recoleccion = 1 + ($n % 4);
            $cliente = 1 + ($n % 8);
            $naviera = 1 + (($puerto + $destino) % 3);
            $transportista = 4 + (($puerto + $recoleccion) % 4);
            $agente = 8 + ($puerto % 3);
            $tipoContenedor = 1 + ($n % 5);

            // Los viejos ya se cerraron; los de las últimas semanas siguen vivos.
            $cerrado = $carga->lt(Carbon::now()->subDays(120));

            DB::table('booking')->insert([
                'booking_id' => $n,
                'booking_number' => $this->perfil->prefijoExpediente().str_pad((string) (1000 + $n), 5, '0', STR_PAD_LEFT),
                'customer_reference' => 'REF-'.str_pad((string) ($n * 37 % 9999), 4, '0', STR_PAD_LEFT),
                'HB' => 'HBL'.str_pad((string) (5000 + $n), 6, '0', STR_PAD_LEFT),
                'client' => $cliente,
                'vessel' => 1 + ($n % 24),
                'carrier_id' => $naviera,
                'transport_id' => $transportista,
                'custom_brocker_id' => $agente,
                'loading_port' => $puerto,
                'loading_EDT' => $carga->toDateString(),
                'dicharge_port_id' => $destino,
                'dicharge_ETA' => $arribo->toDateString(),
                'final_destination_id' => $destino,
                'pick_up_place_id' => $recoleccion,
                'container_type' => $tipoContenedor,
                'commodity' => $trabajos[$n % count($trabajos)],
                'set_point' => in_array($tipoContenedor, [4, 5], true) ? '-18 °C' : null,
                'booking_type' => 2,
                'mode' => 10,
                // Los listados filtran por `is_draft = 0`: en NULL el booking
                // existe pero no sale en ninguna pantalla.
                'is_draft' => 0,
                // Flota propia: solo el perfil de autotransporte la trae.
                'operador_id' => $operadores === 0 ? null : (($n % $operadores) + 1),
                'unidad_id' => $tractores === 0 ? null : (($n % $tractores) + 1),
                'caja_id' => $cajas === 0 ? null : $tractores + (($n % $cajas) + 1),
                'locked' => $cerrado ? 1 : 0,
                'created_at' => $creado->toDateTimeString(),
                'modified_at' => $creado->toDateTimeString(),
                'created_by' => 3,
                'modified_by' => 3,
            ]);

            $this->contenedores($n, $tipoContenedor, $carga);
            $this->gastosDeCarretera($n, $carga);
            $this->continuidad($n, $carga, $arribo, $cerrado);

            $this->bookings[$n] = [
                'cliente' => $cliente,
                'naviera' => $naviera,
                'transportista' => $transportista,
                'agente' => $agente,
                'carga' => $carga,
                'cerrado' => $cerrado,
                'puerto' => $puerto,
                'destino' => $destino,
                'recoleccion' => $recoleccion,
            ];
        }
    }

    /**
     * Combustible y casetas del viaje. Solo con flota propia: quien subcontrata
     * el transporte no paga diésel.
     *
     * El odómetro avanza viaje a viaje para que el rendimiento se pueda calcular
     * de verdad; con cargas sueltas la columna saldría siempre vacía.
     */
    private function gastosDeCarretera(int $booking, Carbon $carga): void
    {
        if ($this->perfil->unidadesFlota() === []) {
            return;
        }

        $tractores = max(1, count(array_filter($this->perfil->unidadesFlota(), fn ($u) => $u['tipo'] === 'tractor')));
        $unidad = ($booking % $tractores) + 1;
        $litros = 180 + ($booking % 5) * 20;

        DB::table('gasto_viaje')->insert([
            'booking' => $booking,
            'tipo' => 'combustible',
            'fecha' => $carga->toDateString(),
            'unidad_id' => $unidad,
            'litros' => $litros,
            'precio_litro' => 25.4,
            'odometro' => 150000 + $booking * 780 + $unidad * 9000,
            'importe' => round($litros * 25.4, 2),
            'created_at' => $carga->toDateTimeString(),
        ]);

        DB::table('gasto_viaje')->insert([
            'booking' => $booking,
            'tipo' => 'caseta',
            'fecha' => $carga->copy()->addDay()->toDateString(),
            'unidad_id' => $unidad,
            'descripcion' => 'Casetas de la ruta',
            'importe' => 900 + ($booking % 7) * 110,
            'created_at' => $carga->toDateTimeString(),
        ]);
    }

    private function contenedores(int $booking, int $tipo, Carbon $carga): void
    {
        foreach (range(1, mt_rand(1, 3)) as $i) {
            DB::table('containers')->insert([
                'booking' => $booking,
                'quantity' => 1,
                'comodity' => 'Carga general',
                'container_type' => $tipo,
                'number' => 'DEMU'.str_pad((string) ($booking * 10 + $i), 7, '0', STR_PAD_LEFT),
                'seal' => 'SL'.str_pad((string) ($booking * 13 + $i), 6, '0', STR_PAD_LEFT),
                'pick_up_date' => $carga->copy()->subDays(3)->toDateTimeString(),
                'created_at' => $carga->toDateString(),
                'modified_at' => $carga->toDateString(),
                'created_by' => 3,
                'modified_by' => 3,
            ]);
        }
    }

    /**
     * Continuidad y lista de verificación.
     *
     * Un embarque cerrado lleva todos los hitos marcados; uno vivo se queda a
     * medias a propósito, que es lo que hace que la bandeja de avisos y el
     * reporte de continuidad tengan algo que enseñar.
     */
    private function continuidad(int $booking, Carbon $carga, Carbon $arribo, bool $cerrado): void
    {
        $hitos = [
            'pickup_date' => $carga->copy()->subDays(4),
            'doc_cut_of' => $carga->copy()->subDays(3),
            'SI_date' => $carga->copy()->subDays(2),
            'draf_client' => $carga->copy()->subDay(),
            'gated_IN' => $carga->copy()->subDay(),
            'cleared' => $carga->copy(),
            'departure' => $carga->copy()->addDay(),
            'bl_payment' => $carga->copy()->addDays(3),
            'swb' => $carga->copy()->addDays(5),
            'corrected_draft' => $carga->copy()->addDays(6),
            'vgm' => $carga->copy()->subDays(2),
            'insurance' => $carga->copy()->subDays(5),
            'delivered' => $arribo->copy(),
            'gated_out' => $arribo->copy()->addDays(2),
        ];

        // En el embarque vivo se dejan sin capturar los últimos hitos.
        $capturados = $cerrado ? count($hitos) : mt_rand(4, 9);

        $fila = ['booking' => $booking, 'modality' => 1 + ($booking % 4), 'modified_at' => $carga->toDateTimeString(), 'modified_by' => 3];
        $verificacion = ['booking' => $booking, 'modified_by' => 3, 'trash' => 0];

        foreach (array_slice($hitos, 0, $capturados, true) as $columna => $fecha) {
            $fila[$columna] = $fecha->toDateTimeString();
            $verificacion[$columna.'_chk_date'] = $fecha->toDateTimeString();
            $verificacion[$columna.'_chk_by'] = 3;
        }

        DB::table('booking_continuity')->insert($fila);
        DB::table('check_list')->insert($verificacion);

        // Y los mismos hitos como filas, que es de donde los lee la rejilla
        // desde que dejaron de ser columnas. La tabla `hito` no se toca: es un
        // catálogo y lo siembra la migración.
        $catalogo = DB::table('hito')->pluck('hito_id', 'columna_legado');

        foreach (array_slice($hitos, 0, $capturados, true) as $columna => $fecha) {
            if (isset($catalogo[$columna])) {
                DB::table('hito_por_expediente')->insert([
                    'booking' => $booking,
                    'hito_id' => $catalogo[$columna],
                    'fecha' => $fecha->toDateTimeString(),
                    'modified_by' => 3,
                    'modified_at' => $carga->toDateTimeString(),
                ]);
            }
        }
    }

    /**
     * Factura al cliente y costos de los tres proveedores, por cada embarque.
     *
     * Los importes se arman con margen: la venta va por encima del costo, para
     * que la utilidad por booking dé un número creíble y no una pérdida.
     */
    private function facturacion(): void
    {
        $transaccion = 0;
        $folio = 0;
        $solicitud = 0;

        foreach ($this->bookings as $booking => $datos) {
            $fecha = $datos['carga']->copy()->addDays(mt_rand(1, 6));

            if ($fecha->gt(Carbon::now())) {
                $fecha = Carbon::now();
            }

            // Nunca en fin de semana: no hay tipo de cambio publicado ese día y
            // la columna «TC» de los listados saldría vacía en la demostración.
            while ($fecha->isWeekend()) {
                $fecha->subDay();
            }

            // --- Factura al cliente (tran_type 0). Va en dólares, como el flete.
            $folio++;
            $transaccion++;
            $cobrada = $datos['cerrado'] && mt_rand(1, 10) <= 8;

            DB::table('transaction')->insert([
                'transc_id' => $transaccion,
                'tran_date' => $fecha->toDateString(),
                'tran_number' => 'F-'.str_pad((string) $folio, 5, '0', STR_PAD_LEFT),
                'account' => 2,
                'company_id' => 1,
                'booking' => $booking,
                'customer' => $datos['cliente'],
                'tran_type' => 0,
                'invoice_type' => 1,
                'open' => 1,
                'active' => 1,
                'paid' => $cobrada ? 1 : 0,
                'cancelled' => 0,
                'payment_request' => 0,
                'pdf_attach' => '',
                'created_at' => $fecha->toDateTimeString(),
                'modified_at' => $fecha->toDateTimeString(),
                'created_by' => 2,
                'modified_by' => 2,
            ]);

            $c = $this->perfil->conceptos();

            $this->conceptos($transaccion, [
                [$c['venta_principal']['descripcion'], $c['venta_principal']['cargo'], 1, 1800 + $datos['puerto'] * 40 + $datos['destino'] * 65],
                [$c['maniobras']['descripcion'], $c['maniobras']['cargo'], 1, 260 + $datos['puerto'] * 12],
                [$c['sueltos'][0]['nombre'], $c['sueltos'][0]['cargo'], 1, 95],
            ]);

            // --- Costos: naviera (USD), transportista (MXN) y agente (MXN).
            $costos = [
                [$datos['naviera'], 2, [[$c['costo_principal']['descripcion'], $c['costo_principal']['cargo'], 1, 1450 + $datos['puerto'] * 35 + $datos['destino'] * 50]]],
                [$datos['transportista'], 1, [[$c['acarreo']['descripcion'], $c['acarreo']['cargo'], 1, 12000 + $datos['puerto'] * 500 + $datos['recoleccion'] * 350]]],
                [$datos['agente'], 1, [[$c['tramite']['descripcion'], $c['tramite']['cargo'], 1, 6500 + $datos['puerto'] * 250], [$c['terceros'], 6, 1, 1800]]],
            ];

            foreach ($costos as [$proveedor, $divisa, $lineas]) {
                $transaccion++;
                $pagado = $datos['cerrado'] && mt_rand(1, 10) <= 7;
                $solicitado = $pagado || ($datos['cerrado'] && mt_rand(1, 10) <= 5);

                if ($solicitado) {
                    $solicitud++;
                }

                DB::table('transaction')->insert([
                    'transc_id' => $transaccion,
                    'tran_date' => $fecha->toDateString(),
                    'tran_number' => 'P-'.str_pad((string) $transaccion, 6, '0', STR_PAD_LEFT),
                    'account' => $divisa,
                    'company_id' => 1,
                    'booking' => $booking,
                    'vendor' => $proveedor,
                    'tran_type' => 1,
                    'invoice_type' => null,
                    'open' => 1,
                    'active' => 1,
                    'paid' => $pagado ? 1 : 0,
                    'cancelled' => 0,
                    'payment_request' => $solicitado ? 1 : 0,
                    'request_id' => $solicitado ? $solicitud : null,
                    'request_at' => $solicitado ? $fecha->copy()->addDays(2)->toDateTimeString() : null,
                    'paid_at' => $pagado ? $fecha->copy()->addDays(9)->toDateTimeString() : null,
                    'bank_id' => $pagado ? ($divisa === 2 ? 2 : 1) : null,
                    'pdf_attach' => '',
                    'created_at' => $fecha->toDateTimeString(),
                    'modified_at' => $fecha->toDateTimeString(),
                    'created_by' => 2,
                    'modified_by' => 2,
                ]);

                $total = $this->conceptos($transaccion, $lineas);

                if ($solicitado) {
                    $this->solicitud($solicitud, $transaccion, $proveedor, $divisa, $total, $fecha, $pagado);
                }
            }
        }
    }

    /**
     * Conceptos de una transacción. Devuelve el total con impuestos, que es lo
     * que necesita la solicitud de pago.
     *
     * @param  array<int, array{0: string, 1: int, 2: float, 3: float}>  $lineas
     */
    private function conceptos(int $transaccion, array $lineas): float
    {
        static $tasas = null;
        $tasas ??= DB::table('charge_type')->pluck('tax_rate', 'charge_type_id')->all();
        static $retenciones = null;
        $retenciones ??= DB::table('charge_type')->pluck('tax_retention', 'charge_type_id')->all();

        $total = 0.0;

        foreach ($lineas as [$descripcion, $tipo, $cantidad, $precio]) {
            DB::table('charge')->insert([
                'transaction' => $transaccion,
                'type' => $tipo,
                'description' => $descripcion,
                'quantity' => $cantidad,
                'unit' => 1,
                'price' => $precio,
                'prepaid' => 0,
            ]);

            $subtotal = $cantidad * $precio;
            $total += $subtotal
                + $subtotal * (float) ($tasas[$tipo] ?? 0)
                - $subtotal * (float) ($retenciones[$tipo] ?? 0);
        }

        return round($total, 2);
    }

    private function solicitud(int $id, int $transaccion, int $proveedor, int $divisa, float $total, Carbon $fecha, bool $pagada): void
    {
        $pedida = $fecha->copy()->addDays(2);

        DB::table('payment_request')->insert([
            'request_id' => $id,
            'number' => str_pad((string) $id, 6, '0', STR_PAD_LEFT),
            'temp_number' => $id,
            'amount' => $total,
            'total_to_pay' => $total,
            'provider_id' => $proveedor,
            'currency_id' => $divisa,
            'bank_id' => $divisa === 2 ? 2 : 1,
            'type' => 1,
            'paid' => $pagada ? 1 : 0,
            'opened' => $pagada ? 0 : 1,
            'date' => $pedida->toDateString(),
            'created_at' => $pedida->toDateTimeString(),
            'modified_at' => $pedida->toDateTimeString(),
            'created_by' => 2,
            'modified_by' => 2,
        ]);

        DB::table('payments_by_transaction')->insert([
            'request_id' => $id,
            'transc_id' => $transaccion,
            'amount' => $total,
            'paid' => $pagada ? 1 : 0,
            'created_at' => $pedida->toDateString(),
            'created_by' => 2,
            'modified_at' => $pedida->toDateTimeString(),
            'modified_by' => 2,
        ]);
    }
}
