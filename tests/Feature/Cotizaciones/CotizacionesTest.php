<?php

namespace Tests\Feature\Cotizaciones;

use App\Livewire\Cotizaciones\QuoteDetail;
use App\Livewire\Cotizaciones\QuoteList;
use App\Livewire\Operations\BookingForm;
use App\Mail\QuoteMail;
use App\Models\Core\Booking;
use App\Models\User;
use App\Support\Billing\BillingBlock;
use App\Support\Billing\ServiceMatcher;
use App\Support\Cotizaciones\Cotizaciones;
use App\Support\Pdf\QuoteDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Cotizaciones de viaje: se arman sobre la ruta, se mandan, se aceptan y el
 * viaje que sale de ellas se factura con lo cotizado.
 *
 * Cuentas a mano: flete 10 000 (IVA 16 %, retención 4 %) + maniobras 1 000
 * (IVA 16 %) = subtotal 11 000, IVA 1 760, retención 400, total 12 360.
 */
class CotizacionesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        config(['marca.modalidades' => 'terrestre']);

        DB::table('account')->insert(['account_id' => 1, 'account_name' => 'Pesos', 'prefix' => 'MXN', 'default' => 1]);
        DB::table('charge_type')->insert([
            ['charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0.16, 'tax_retention' => 0.04],
            ['charge_type_id' => 2, 'charge_type_name' => 'Maniobras', 'tax_rate' => 0.16, 'tax_retention' => 0],
        ]);
        DB::table('client')->insert([
            ['client_id' => 1, 'fullName' => 'Cementos', 'email' => 'compras@cementos.test'],
            ['client_id' => 2, 'fullName' => 'Aceros', 'email' => ''],
        ]);
        DB::table('loading_ports')->insert(['port_id' => 1, 'port_name' => 'Monterrey', 'deleted' => 0]);
        DB::table('dicharge_port')->insert(['dicharge_port_id' => 1, 'name' => 'Ciudad de México', 'deleted' => 0]);
        DB::table('ruta')->insert(['ruta_id' => 1, 'origen_id' => 1, 'destino_id' => 1, 'km' => 900, 'horas' => 14, 'rendimiento' => 2.25, 'activo' => 1]);
        DB::table('precio_diesel')->insert(['fecha' => '2026-01-01', 'precio' => 25]);
        DB::table('tarifa_ruta')->insert([
            ['ruta_id' => 1, 'tipo' => 'venta', 'concepto' => 'Flete', 'client_id' => null, 'precio' => 10000, 'vigente_desde' => '2026-01-01', 'charge_type_id' => 1],
            ['ruta_id' => 1, 'tipo' => 'venta', 'concepto' => 'Maniobras', 'client_id' => null, 'precio' => 1000, 'vigente_desde' => '2026-01-01', 'charge_type_id' => 2],
            ['ruta_id' => 1, 'tipo' => 'venta', 'concepto' => 'Flete', 'client_id' => 2, 'precio' => 9500, 'vigente_desde' => '2026-01-01', 'charge_type_id' => 1],
        ]);
    }

    private function admin(int $rol = User::ROLE_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'ventas'.$rol, 'password' => 'secreto-de-prueba',
            'role' => $rol, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);
    }

    private function crear(array $datos = ['cliente' => '1']): int
    {
        $pantalla = Livewire::actingAs($this->admin())->test(QuoteList::class)
            ->set('ruta', '1')->set('fechaCarga', '2026-09-30');

        foreach ($datos as $campo => $valor) {
            $pantalla->set($campo, $valor);
        }

        $pantalla->call('crear')->assertHasNoErrors();

        return (int) DB::table('cotizacion')->max('cotizacion_id');
    }

    private function ficha(int $id): Testable
    {
        return Livewire::actingAs($this->admin())->test(QuoteDetail::class, ['cotizacion' => $id]);
    }

    /** @return array<string, float> */
    private function conceptos(int $id): array
    {
        return DB::table('cotizacion_renglon')->where('cotizacion_id', $id)->orderBy('renglon_id')
            ->pluck('precio', 'concepto')->map(fn ($p) => (float) $p)->all();
    }

    public function test_la_cotizacion_nace_con_los_precios_de_la_ruta_y_el_correo_del_cliente(): void
    {
        $id = $this->crear();

        $this->assertSame(['Flete' => 10000.0, 'Maniobras' => 1000.0], $this->conceptos($id));
        $this->assertSame(['borrador', 'compras@cementos.test'], [
            DB::table('cotizacion')->value('estado'), DB::table('cotizacion')->value('correo'),
        ]);
    }

    public function test_el_cliente_con_tarifa_especial_la_ve_en_su_cotizacion(): void
    {
        $this->assertSame(['Flete' => 9500.0, 'Maniobras' => 1000.0], $this->conceptos($this->crear(['cliente' => '2'])));
    }

    public function test_se_cotiza_a_un_prospecto_que_todavia_no_es_cliente(): void
    {
        $id = $this->crear(['cliente' => '', 'prospecto' => 'Lácteos del Norte', 'correo' => 'hola@lacteos.test']);

        $this->assertSame('Lácteos del Norte', Cotizaciones::destinatario(DB::table('cotizacion')->where('cotizacion_id', $id)->first()));
    }

    public function test_sin_cliente_ni_prospecto_no_se_crea(): void
    {
        Livewire::actingAs($this->admin())->test(QuoteList::class)->set('ruta', '1')
            ->call('crear')->assertHasErrors(['cliente', 'prospecto']);
    }

    public function test_los_totales_desglosan_iva_y_retencion(): void
    {
        $id = $this->crear();

        $this->assertSame(['subtotal' => 11000.0, 'iva' => 1760.0, 'retencion' => 400.0, 'total' => 12360.0],
            Cotizaciones::totales(Cotizaciones::renglones($id)));
    }

    public function test_en_borrador_se_ajusta_el_precio_y_se_agregan_conceptos(): void
    {
        $id = $this->crear();
        $flete = (int) DB::table('cotizacion_renglon')->where('concepto', 'Flete')->value('renglon_id');

        $this->ficha($id)
            ->set("renglones.{$flete}.precio", '10500')->call('guardar')
            ->set('concepto', 'Estadía')->set('tipoCargo', '2')->set('cantidad', '2')->set('precio', '1800')->call('agregarRenglon')
            ->assertHasNoErrors();

        $this->assertSame(['Flete' => 10500.0, 'Maniobras' => 1000.0, 'Estadía' => 1800.0], $this->conceptos($id));
    }

    public function test_enviar_manda_el_pdf_y_la_deja_fija(): void
    {
        Mail::fake();
        $id = $this->crear();

        $this->ficha($id)->call('enviar')->assertHasNoErrors();

        Mail::assertSent(QuoteMail::class, fn (QuoteMail $m) => $m->hasTo('compras@cementos.test'));
        $this->assertSame('enviada', DB::table('cotizacion')->value('estado'));
        $this->ficha($id)->call('agregarRenglon')->assertStatus(422);
    }

    public function test_sin_correo_no_se_envia(): void
    {
        Mail::fake();
        $id = $this->crear(['cliente' => '2']);

        $this->ficha($id)->call('enviar')->assertHasErrors('correo');

        Mail::assertNothingSent();
        $this->assertSame('borrador', DB::table('cotizacion')->value('estado'));
    }

    public function test_una_enviada_que_paso_su_vigencia_esta_vencida(): void
    {
        DB::table('cotizacion')->insert(['cotizacion_id' => 9, 'numero' => 'COT-9', 'client_id' => 1, 'ruta_id' => 1, 'vigencia' => now()->subDay()->toDateString(), 'estado' => 'enviada']);

        $this->assertSame('vencida', Cotizaciones::estado(DB::table('cotizacion')->where('cotizacion_id', 9)->first()));
    }

    public function test_aceptada_se_convierte_en_viaje_con_cliente_ruta_y_fechas(): void
    {
        $id = $this->crear();
        $this->ficha($id)->call('responder', 'aceptada')->call('convertir')
            ->assertRedirect(route('operations.bookings.create', ['cotizacion' => $id]));

        Livewire::withQueryParams(['cotizacion' => $id])->actingAs($this->admin())->test(BookingForm::class)
            ->assertSet('cotizacionId', $id)->assertSet('clientId', '1')->assertSet('loadingPort', '1')
            ->assertSet('dischargePort', '1')->assertSet('loadingDate', '2026-09-30')
            // 900 km: dos jornadas de 650.
            ->assertSet('arrivalDate', '2026-10-02');
    }

    /** El viaje se factura con lo cotizado aunque la ruta haya subido su tarifa después. */
    public function test_el_viaje_de_una_cotizacion_se_factura_con_lo_cotizado(): void
    {
        $id = $this->crear();
        DB::table('tarifa_ruta')->insert(['ruta_id' => 1, 'tipo' => 'venta', 'concepto' => 'Flete', 'precio' => 12000, 'vigente_desde' => '2026-09-01', 'charge_type_id' => 1]);
        DB::table('booking')->insert(['booking_id' => 1, 'booking_number' => 'VJ-1', 'client' => 1, 'mode' => 10, 'is_draft' => 0, 'locked' => 0,
            'loading_port' => 1, 'dicharge_port_id' => 1, 'loading_EDT' => '2026-09-30', 'unidad_id' => 3, 'cotizacion_id' => $id]);

        $this->assertSame(['Flete' => 10000.0, 'Maniobras' => 1000.0], collect(app(ServiceMatcher::class)->forBlock(Booking::findOrFail(1), BillingBlock::Invoice))
            ->mapWithKeys(fn ($c) => [$c->description => $c->price])->all());
    }

    public function test_con_viajes_ya_no_se_regresa_a_borrador(): void
    {
        $id = $this->crear();
        DB::table('cotizacion')->update(['estado' => 'aceptada']);
        DB::table('booking')->insert(['booking_id' => 1, 'booking_number' => 'VJ-1', 'client' => 1, 'mode' => 10, 'cotizacion_id' => $id]);

        $this->ficha($id)->call('reabrir')->assertStatus(422);
    }

    /** El PDF lleva folio, ruta y total; el margen es interno y no sale. */
    public function test_el_pdf_lleva_la_cotizacion_sin_el_margen(): void
    {
        $id = $this->crear();
        $html = app(QuoteDocument::class)->html($id);

        $this->assertStringContainsString('COT-00001', $html);
        $this->assertStringContainsString('Monterrey → Ciudad de México', $html);
        $this->assertStringContainsString('12,360.00', $html);
        $this->assertStringNotContainsString(__('Margen estimado'), $html);
    }

    public function test_el_pdf_se_sirve(): void
    {
        $id = $this->crear();

        $respuesta = $this->actingAs($this->admin())->get(route('cotizaciones.pdf', $id));

        $respuesta->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_quien_no_es_administrador_no_entra(): void
    {
        $this->actingAs($this->admin(User::ROLE_USER))->get(route('cotizaciones.index'))->assertForbidden();
    }

    public function test_sin_autotransporte_no_hay_cotizaciones(): void
    {
        config(['marca.modalidades' => 'maritimo']);

        $this->actingAs($this->admin())->get(route('cotizaciones.index'))->assertNotFound();
    }
}
