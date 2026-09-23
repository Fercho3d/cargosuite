<?php

namespace Tests\Feature\Payments;

use App\Livewire\Payments\SettlementManager;
use App\Models\User;
use App\Support\Settlements\DriverSettlement;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Liquidación de operadores: lo que se le paga a cada quien por sus viajes.
 *
 * ⚠️ **No es nómina fiscal** y estas pruebas no la comprueban: aquí no hay IMSS,
 * ni ISR, ni timbrado. Eso es un producto aparte y regulado.
 */
class LiquidacionOperadoresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        config(['marca.modalidades' => 'terrestre']);

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('loading_ports')->insert([['port_id' => 1, 'port_name' => 'Patio', 'deleted' => 0]]);
        DB::table('dicharge_port')->insert([['dicharge_port_id' => 1, 'name' => 'CDMX', 'deleted' => 0]]);
        DB::table('operador')->insert([[
            'operador_id' => 1, 'nombre' => 'Miguel Ramírez', 'activo' => 1,
            'tarifa_tipo' => 'fijo', 'tarifa_valor' => 3500,
        ]]);

        foreach ([1, 2, 3] as $n) {
            DB::table('booking')->insert([[
                'booking_id' => $n, 'booking_number' => 'VJ-'.$n, 'client' => 1, 'mode' => 10,
                'is_draft' => 0, 'operador_id' => 1, 'loading_port' => 1, 'dicharge_port_id' => 1,
                'loading_EDT' => '2026-06-'.str_pad((string) ($n * 5), 2, '0', STR_PAD_LEFT),
            ]]);
        }
    }

    private function admin(int $rol = User::ROLE_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'jefa'.$rol, 'password' => 'secreto-de-prueba',
            'role' => $rol, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);
    }

    private function pantalla(int $rol = User::ROLE_ADMIN): Testable
    {
        return Livewire::actingAs($this->admin($rol))->test(SettlementManager::class);
    }

    private function crear(): Testable
    {
        return $this->pantalla()
            ->set('nuevoOperador', '1')->set('desde', '2026-06-01')->set('hasta', '2026-06-30')
            ->call('crear');
    }

    /** Propone antes de escribir: trae los viajes del periodo con su importe. */
    public function test_la_liquidacion_llega_con_los_viajes_del_periodo(): void
    {
        $this->crear()->assertHasNoErrors();

        $renglones = DB::table('liquidacion_renglon')->get();

        $this->assertCount(3, $renglones);
        $this->assertEqualsWithDelta(3500.0, (float) $renglones->first()->importe, 0.01);
        $this->assertSame(10500.0, DriverSettlement::totales(1)['total']);
    }

    /**
     * Lo que sostiene el módulo: un viaje ya liquidado NO vuelve a aparecer. Sin
     * esto, dos liquidaciones que se traslapen le pagarían dos veces el mismo
     * viaje, y no se descubre hasta que alguien cuadra el mes.
     */
    public function test_un_viaje_ya_liquidado_no_se_paga_dos_veces(): void
    {
        $this->crear();

        $this->assertCount(0, DriverSettlement::viajesPendientes(1, '2026-06-01', '2026-06-30'));

        $this->pantalla()
            ->set('nuevoOperador', '1')->set('desde', '2026-06-01')->set('hasta', '2026-06-30')
            ->call('crear');

        $this->assertSame(3, DB::table('liquidacion_renglon')->count(), 'Se duplicaron los viajes.');
    }

    /** El porcentaje se calcula sobre lo facturado del viaje, sin impuestos. */
    public function test_la_tarifa_por_porcentaje_sale_de_lo_facturado(): void
    {
        DB::table('operador')->where('operador_id', 1)
            ->update(['tarifa_tipo' => 'porcentaje', 'tarifa_valor' => 10]);

        DB::table('charge_type')->insert([[
            'charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0.16,
            'tax_retention' => 0, 'non_deductible' => 0,
        ]]);
        DB::table('transaction')->insert([[
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 0, 'customer' => 1,
            'active' => 1, 'cancelled' => 0, 'tran_date' => '2026-06-05',
        ]]);
        DB::table('charge')->insert([[
            'charge_id' => 1, 'transaction' => 1, 'type' => 1, 'quantity' => 1,
            'price' => 20000, 'description' => 'Flete',
        ]]);

        $operador = DB::table('operador')->where('operador_id', 1)->first();

        $this->assertSame(2000.0, DriverSettlement::propuestaPorViaje($operador, 1));
    }

    /** Sin tarifa capturada se propone cero: mejor visible que inventado. */
    public function test_sin_tarifa_se_propone_cero(): void
    {
        DB::table('operador')->where('operador_id', 1)->update(['tarifa_tipo' => null, 'tarifa_valor' => null]);

        $operador = DB::table('operador')->where('operador_id', 1)->first();

        $this->assertSame(0.0, DriverSettlement::propuestaPorViaje($operador, 1));
    }

    public function test_las_deducciones_restan(): void
    {
        $this->crear()
            ->set('concepto', 'Anticipo')->set('tipo', 'deduccion')->set('importe', '2000')
            ->call('agregarRenglon')->assertHasNoErrors();

        $totales = DriverSettlement::totales(1);

        $this->assertSame(10500.0, $totales['percepciones']);
        $this->assertSame(2000.0, $totales['deducciones']);
        $this->assertSame(8500.0, $totales['total']);
    }

    /** Una liquidación pagada no se toca: el dinero ya salió. */
    public function test_una_pagada_no_se_modifica(): void
    {
        $this->crear()->call('pagar', 1);

        $this->assertSame('pagada', DB::table('liquidacion')->value('estado'));

        $this->pantalla()->call('ver', 1)
            ->set('concepto', 'Bono')->set('tipo', 'percepcion')->set('importe', '500')
            ->call('agregarRenglon');

        $this->assertSame(3, DB::table('liquidacion_renglon')->count());
    }

    /** Reabrir deshace un pago registrado: eso es del super administrador. */
    public function test_solo_el_super_administrador_reabre(): void
    {
        $this->crear()->call('pagar', 1);

        $this->pantalla(User::ROLE_ADMIN)->call('reabrir', 1)->assertForbidden();
        $this->assertSame('pagada', DB::table('liquidacion')->value('estado'));

        $this->pantalla(User::ROLE_SUPER_ADMIN)->call('reabrir', 1);
        $this->assertSame('abierta', DB::table('liquidacion')->value('estado'));
    }

    /** Al borrarla se van sus renglones: si no, sus viajes quedarían liquidados para siempre. */
    public function test_borrarla_libera_sus_viajes(): void
    {
        $this->crear()->call('borrar', 1);

        $this->assertSame(0, DB::table('liquidacion_renglon')->count());
        $this->assertCount(3, DriverSettlement::viajesPendientes(1, '2026-06-01', '2026-06-30'));
    }

    public function test_quien_no_es_administrador_no_entra(): void
    {
        Livewire::actingAs($this->admin(User::ROLE_USER))->test(SettlementManager::class)->assertForbidden();
    }
}
