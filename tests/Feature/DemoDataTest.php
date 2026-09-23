<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\DemoDatabaseTestCase;

/**
 * La base de demostración está sana: todas las pantallas abren con sus datos.
 *
 * Es la prueba que hay que correr después de volver a sembrarla, porque un dato
 * de ejemplo mal puesto no rompe nada, solo deja pantallas vacías —y eso, en una
 * demostración, es peor que un error—. Ya cazó una: los bookings se sembraban
 * con `is_draft` en NULL y no salían en ningún listado.
 *
 *   php artisan test --group=demo
 *
 * Va fuera de la corrida normal (como las de paridad) porque necesita MySQL con
 * la base sembrada; sin ella se salta.
 */
#[Group('demo')]
class DemoDataTest extends DemoDatabaseTestCase
{
    protected function base(): string
    {
        return 'cargosuite_demo';
    }

    public function test_todas_las_pantallas_abren_con_los_datos_de_ejemplo(): void
    {
        // El booking 1 es de los viejos (cerrado) y el último sigue abierto:
        // hacen falta los dos, porque la generación de facturación se niega a
        // trabajar sobre un booking cerrado y esa negativa también hay que verla.
        $ultimo = (int) DB::table('booking')->max('booking_id');

        $rutas = [
            '/dashboard', '/transacciones', '/transacciones/costos', '/transacciones/todas',
            '/transacciones/1', '/transacciones/reporte/booking', '/pagos/solicitudes',
            '/pagos/reporte/clientes', '/pagos/reporte/proveedores', '/pagos/reporte/general',
            '/operacion/bookings', '/operacion/bookings/1', '/operacion/bookings/1/historial',
            "/operacion/bookings/{$ultimo}", "/operacion/bookings/{$ultimo}/generar",
            '/operacion/continuidad', '/terceros/clientes', '/terceros/proveedores',
            '/terceros/servicios', '/catalogos/companias', '/tipos-de-cambio',
            '/avisos', '/usuarios', '/seguridad',
        ];

        $this->abre($rutas);

        $cliente = User::query()->where('username', 'demo.cliente')->first();
        $this->assertSame(200, $this->actingAs($cliente)->get('/portal')->status());
    }

    /**
     * La rejilla de continuidad enseña fechas de verdad.
     *
     * Un 200 no basta: desde que los hitos son filas y no columnas, la rejilla
     * podría pintarse entera vacía sin que nada falle —y una pantalla en blanco
     * en una demostración es peor que un error—.
     */
    public function test_la_rejilla_de_continuidad_trae_fechas(): void
    {
        $respuesta = $this->actingAs($this->admin())->get('/operacion/continuidad');

        $respuesta->assertOk();
        // El rótulo sale en el idioma que eligió demo.admin (su preferencia
        // vive en la base sembrada): se acepta en los dos.
        $this->assertMatchesRegularExpression('/Zarpe|Departure/', (string) $respuesta->getContent());

        // Al menos una celda con fecha capturada, no solo el punto de «vacío».
        // La rejilla pinta «dd/mm» (o «dd/mm/aa» fuera del año en curso) como
        // contenido de la celda; el marcador de posición del filtro trae una
        // fecha completa dentro de un atributo y por eso se exige que vaya
        // entre etiquetas.
        $this->assertMatchesRegularExpression(
            '~>\s*\d{2}/\d{2}(/\d{2})?\s*<~',
            (string) $respuesta->getContent(),
            'La rejilla de continuidad salió sin una sola fecha.',
        );

        $this->assertGreaterThan(0, DB::table('hito_por_expediente')->count());
    }

    /** Cada embarque tiene su factura, sus costos y sus contenedores. */
    public function test_la_operacion_de_ejemplo_esta_completa(): void
    {
        $bookings = DB::table('booking')->count();

        $this->assertGreaterThan(20, $bookings);
        $this->assertSame($bookings, DB::table('transaction')->where('tran_type', 0)->count());
        $this->assertSame($bookings * 3, DB::table('transaction')->where('tran_type', 1)->count());
        $this->assertSame(0, DB::table('booking')->whereNull('is_draft')->count());
        $this->assertSame(0, DB::table('transaction')->whereNotExists(
            fn ($q) => $q->select(DB::raw(1))->from('charge')->whereColumn('charge.transaction', 'transaction.transc_id')
        )->count(), 'Hay transacciones sin conceptos: los importes saldrían en cero.');
    }
}
