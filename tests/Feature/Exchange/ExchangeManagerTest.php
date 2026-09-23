<?php

namespace Tests\Feature\Exchange;

use App\Livewire\Exchange\ExchangeManager;
use App\Models\Core\Exchange;
use App\Models\User;
use App\Support\ExchangeRates;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Tipos de cambio.
 *
 * La regla que más importa: no puede haber dos para la misma moneda el mismo
 * día, porque el motor de consulta une por esa pareja y duplicaría los importes.
 */
class ExchangeManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        Http::preventStrayRequests();

        DB::table('account')->insert([
            ['account_id' => 1, 'account_name' => 'Pesos', 'default' => 1, 'prefix' => 'MXN'],
            ['account_id' => 2, 'account_name' => 'Dólares', 'default' => null, 'prefix' => 'USD'],
        ]);
    }

    /** Super administrador: en Yii2 el `ExchangeController` no dejaba entrar a nadie más. */
    private function usuario(int $rol = User::ROLE_SUPER_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]);
    }

    private function pantalla(int $rol = User::ROLE_SUPER_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(ExchangeManager::class);
    }

    public function test_capturar_un_tipo_de_cambio(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('date', '2026-02-10')
            ->set('account', '2')
            ->set('value', '17.4321')
            ->call('save')
            ->assertHasNoErrors();

        $tipo = Exchange::first();

        $this->assertSame(17.4321, $tipo->exchange_value);
        $this->assertSame('2026-02-10', $tipo->date_exchange->toDateString());
    }

    /** La fecha de publicación la pone Banxico; una captura a mano no la tiene, como en el original. */
    public function test_la_captura_manual_no_inventa_fecha_de_publicacion(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('date', '2026-02-10')
            ->set('account', '2')
            ->set('value', '17.4321')
            ->call('save');

        $this->assertNull(Exchange::first()->taken_date);
    }

    public function test_el_listado_se_acota_por_rango_de_fechas(): void
    {
        Exchange::create(['exchange_id' => 1, 'date_exchange' => '2026-01-15', 'account' => 2, 'exchange_value' => 17]);
        Exchange::create(['exchange_id' => 2, 'date_exchange' => '2026-02-10', 'account' => 2, 'exchange_value' => 18]);
        Exchange::create(['exchange_id' => 3, 'date_exchange' => '2026-03-05', 'account' => 2, 'exchange_value' => 19]);

        $tipos = $this->pantalla()->set('from', '2026-02-01')->set('to', '2026-02-28')->viewData('tipos');

        $this->assertSame([2], $tipos->pluck('exchange_id')->all());
    }

    /** El motor une por (fecha, moneda): dos filas duplicarían los importes. */
    public function test_no_se_repite_la_misma_moneda_el_mismo_dia(): void
    {
        Exchange::create(['exchange_id' => 1, 'date_exchange' => '2026-02-10', 'account' => 2, 'exchange_value' => 17]);

        $this->pantalla()
            ->call('create')
            ->set('date', '2026-02-10')
            ->set('account', '2')
            ->set('value', '18')
            ->call('save')
            ->assertHasErrors('date');

        $this->assertSame(1, Exchange::count());
    }

    public function test_dos_monedas_el_mismo_dia_si_conviven(): void
    {
        Exchange::create(['exchange_id' => 1, 'date_exchange' => '2026-02-10', 'account' => 1, 'exchange_value' => 1]);

        $this->pantalla()
            ->call('create')
            ->set('date', '2026-02-10')
            ->set('account', '2')
            ->set('value', '17.5')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, Exchange::count());
    }

    public function test_el_tipo_de_cambio_no_puede_ser_cero(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('date', '2026-02-10')
            ->set('account', '2')
            ->set('value', '0')
            ->call('save')
            ->assertHasErrors('value');
    }

    public function test_editar_no_choca_consigo_mismo(): void
    {
        Exchange::create(['exchange_id' => 1, 'date_exchange' => '2026-02-10', 'account' => 2, 'exchange_value' => 17]);

        $this->pantalla()
            ->call('edit', 1)
            ->set('value', '17.9')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(17.9, Exchange::find(1)->exchange_value);
    }

    /** `created_at`/`modified_at` son `date` en la base y el original los llenaba. */
    public function test_la_captura_manual_deja_fecha_de_alta_y_de_cambio(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('date', '2026-02-10')
            ->set('account', '2')
            ->set('value', '17.4321')
            ->call('save');

        $fila = DB::table('exchange')->first();

        $this->assertSame([now()->toDateString(), now()->toDateString()], [$fila->created_at, $fila->modified_at]);
    }

    public function test_editar_deja_fecha_de_cambio(): void
    {
        Exchange::create(['exchange_id' => 1, 'date_exchange' => '2026-02-10', 'account' => 2, 'exchange_value' => 17]);

        $this->pantalla()->call('edit', 1)->set('value', '17.9')->call('save');

        $this->assertSame(now()->toDateString(), DB::table('exchange')->value('modified_at'));
    }

    /**
     * Dos capturas al mismo tiempo pasan las dos la revisión previa; la segunda
     * choca con el índice único `uq_date` y debe verse el aviso, no un error.
     */
    public function test_el_duplicado_por_carrera_se_avisa_en_pantalla(): void
    {
        Schema::table('exchange', fn ($table) => $table->unique(['date_exchange', 'account'], 'uq_date'));
        Exchange::creating(function (Exchange $tipo) {
            DB::table('exchange')->insert([
                'date_exchange' => $tipo->getAttributes()['date_exchange'], 'account' => $tipo->account, 'exchange_value' => 17,
            ]);
        });

        $this->pantalla()
            ->call('create')
            ->set('date', '2026-02-10')
            ->set('account', '2')
            ->set('value', '18')
            ->call('save')
            ->assertHasErrors('date');
    }

    public function test_traer_el_del_dia_avisa_cuando_el_dof_no_contesta(): void
    {
        Http::fake(['www.banxico.org.mx/*' => Http::response(['bmx' => ['series' => [['datos' => []]]]])]);

        $this->pantalla()->call('fetchToday')->assertHasErrors('fetch');
    }

    /** El valor de Banxico para el día se guarda tal cual, en la cuenta del dólar. */
    public function test_el_del_dia_se_toma_de_banxico(): void
    {
        Http::fake(['www.banxico.org.mx/*' => Http::response(['bmx' => ['series' => [
            ['idSerie' => 'SF60653', 'datos' => [['fecha' => '24/08/2026', 'dato' => '16.9583']]],
        ]]])]);

        app(ExchangeRates::class)->ensureFor(Carbon::parse('2026-08-24'));

        $this->assertSame(
            [16.9583, 2],
            [(float) Exchange::whereDate('date_exchange', '2026-08-24')->value('exchange_value'), (int) Exchange::value('account')],
        );
    }

    /**
     * La comprobación es por fecha Y moneda: un euro capturado a mano ese día
     * no debe hacer creer que el dólar ya está.
     */
    public function test_un_euro_del_dia_no_impide_traer_el_dolar(): void
    {
        Exchange::create(['exchange_id' => 1, 'date_exchange' => '2026-08-24', 'account' => 3, 'exchange_value' => 19.5]);
        Http::fake(['www.banxico.org.mx/*' => Http::response(['bmx' => ['series' => [
            ['idSerie' => 'SF60653', 'datos' => [['fecha' => '24/08/2026', 'dato' => '16.9583']]],
        ]]])]);

        app(ExchangeRates::class)->ensureFor(Carbon::parse('2026-08-24'));

        $this->assertSame(2, Exchange::whereDate('date_exchange', '2026-08-24')->count());
    }

    public function test_el_alta_automatica_deja_fecha_de_alta(): void
    {
        Http::fake(['www.banxico.org.mx/*' => Http::response(['bmx' => ['series' => [
            ['idSerie' => 'SF60653', 'datos' => [['fecha' => '24/08/2026', 'dato' => '16.9583']]],
        ]]])]);

        app(ExchangeRates::class)->ensureFor(Carbon::parse('2026-08-24'));

        $this->assertSame(now()->toDateString(), DB::table('exchange')->value('created_at'));
    }

    /** Si otra petición lo registró mientras se consultaba a Banxico, ya está: no es una falla. */
    public function test_el_alta_automatica_duplicada_por_carrera_no_se_cuenta_como_falla(): void
    {
        Schema::table('exchange', fn ($table) => $table->unique(['date_exchange', 'account'], 'uq_date'));
        Exchange::creating(function (Exchange $tipo) {
            DB::table('exchange')->insert([
                'date_exchange' => $tipo->getAttributes()['date_exchange'], 'account' => $tipo->account, 'exchange_value' => 17,
            ]);
        });
        Http::fake(['www.banxico.org.mx/*' => Http::response(['bmx' => ['series' => [
            ['idSerie' => 'SF60653', 'datos' => [['fecha' => '24/08/2026', 'dato' => '16.9583']]],
        ]]])]);

        $this->assertTrue(app(ExchangeRates::class)->ensureFor(Carbon::parse('2026-08-24')));
        $this->assertNull(ExchangeRates::failure());
    }

    public function test_con_el_dolar_del_dia_ya_registrado_no_se_consulta_banxico(): void
    {
        Exchange::create(['exchange_id' => 1, 'date_exchange' => '2026-08-24', 'account' => 2, 'exchange_value' => 17]);
        Http::fake();

        $this->assertTrue(app(ExchangeRates::class)->ensureFor(Carbon::parse('2026-08-24')));
        Http::assertNothingSent();
    }

    /** El comando programado de las 07:30: registra el dólar de hoy y avisa si Banxico no contestó. */
    public function test_el_comando_diario_registra_el_dolar_de_hoy(): void
    {
        Carbon::setTestNow('2026-08-24 07:30:00');
        Http::fake(['www.banxico.org.mx/*' => Http::response(['bmx' => ['series' => [
            ['idSerie' => 'SF60653', 'datos' => [['fecha' => '24/08/2026', 'dato' => '16.9583']]],
        ]]])]);

        $this->artisan('exchange:diario')->assertSuccessful();

        $this->assertSame(16.9583, Exchange::whereDate('date_exchange', '2026-08-24')->where('account', 2)->first()->exchange_value);
    }

    public function test_el_comando_diario_falla_cuando_banxico_no_contesta(): void
    {
        Http::fake(['www.banxico.org.mx/*' => Http::failedConnection()]);

        $this->artisan('exchange:diario')->assertFailed();
    }

    public function test_el_comando_diario_esta_programado_de_lunes_a_viernes(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'exchange:diario'));

        $this->assertSame(['30 7 * * 1-5', 'America/Mexico_City'], [$evento?->expression, $evento?->timezone]);
    }

    /** Banxico rechaza el token: cualquier pantalla avisa que hay que llamar al administrador. */
    public function test_avisa_cuando_banxico_rechaza_el_token(): void
    {
        Http::fake(['www.banxico.org.mx/*' => Http::response(['error' => ['mensaje' => 'Token inválido']], 400)]);

        app(ExchangeRates::class)->ensureFor(Carbon::parse('2026-08-24'));

        $this->actingAs($this->usuario())->get(route('dashboard'))
            ->assertSee(__('El token de Banxico venció o no es válido y el tipo de cambio no se está registrando. Contacte a su administrador.'));
    }

    public function test_avisa_cuando_no_se_puede_conectar_con_banxico(): void
    {
        Http::fake(['www.banxico.org.mx/*' => Http::failedConnection()]);

        app(ExchangeRates::class)->ensureFor(Carbon::parse('2026-08-24'));

        $this->actingAs($this->usuario())->get(route('dashboard'))
            ->assertSee(__('No se pudo conectar con Banxico y el tipo de cambio no se está registrando. Contacte a su administrador.'));
    }

    /** En cuanto Banxico vuelve a contestar, el aviso se quita solo. */
    public function test_el_aviso_se_quita_cuando_banxico_contesta(): void
    {
        Http::fake(['www.banxico.org.mx/*' => Http::sequence()
            ->push(['error' => ['mensaje' => 'Token inválido']], 400)
            ->push(['bmx' => ['series' => [['datos' => [['fecha' => '24/08/2026', 'dato' => '16.9583']]]]]]),
        ]);

        app(ExchangeRates::class)->ensureFor(Carbon::parse('2026-08-24'));
        app(ExchangeRates::class)->ensureFor(Carbon::parse('2026-08-24'));

        $this->assertNull(ExchangeRates::failure());
    }

    public function test_quien_no_es_administrador_no_entra(): void
    {
        $this->actingAs($this->usuario(User::ROLE_USER));

        Livewire::test(ExchangeManager::class)->assertForbidden();
    }

    /** Como en Yii2: un administrador normal tampoco entra. */
    public function test_un_administrador_normal_tampoco_entra(): void
    {
        $this->actingAs($this->usuario(User::ROLE_ADMIN));

        Livewire::test(ExchangeManager::class)->assertForbidden();
    }
}
