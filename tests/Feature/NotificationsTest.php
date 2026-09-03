<?php

namespace Tests\Feature;

use App\Livewire\Notifications;
use App\Models\User;
use App\Support\Notifications\Notice;
use App\Support\Notifications\NoticeFeed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Bandeja de avisos.
 *
 * Lo que de verdad importa comprobar es el recorte: sin él la bandeja trae más
 * de mil avisos de embarques de hace años y no sirve para nada.
 */
class NotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Frialsa']]);
        DB::table('account')->insert([['account_id' => 1, 'account_name' => 'Pesos', 'prefix' => 'MXN', 'default' => 1]]);
        DB::table('charge_type')->insert([['charge_type_id' => 1, 'charge_type_name' => 'Flete', 'tax_rate' => 0]]);
    }

    /** Un booking abierto con carga reciente: de esos habla la bandeja. */
    private function bookingVivo(int $id, array $cambios = []): void
    {
        DB::table('booking')->insert([array_merge([
            'booking_id' => $id, 'booking_number' => 'BK-'.$id, 'client' => 1, 'mode' => 10,
            'is_draft' => 0, 'locked' => 0, 'loading_EDT' => now()->subDays(5)->toDateString(),
        ], $cambios)]);
    }

    private function usuario(): User
    {
        return User::create([
            'username' => 'operador', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_ADMIN, 'status' => 1,
        ]);
    }

    private function avisos()
    {
        Cache::flush();

        return app(NoticeFeed::class)->all();
    }

    public function test_un_embarque_que_ya_cargo_y_no_tiene_factura_sale_en_la_bandeja(): void
    {
        $this->bookingVivo(1);
        DB::table('containers')->insert([['container_ID' => 1, 'booking' => 1, 'container_type' => 1, 'quantity' => 1]]);

        $avisos = $this->avisos()->where('grupo', 'sin_facturar');

        $this->assertCount(1, $avisos);
        $this->assertSame('BK-1', $avisos->first()->titulo);
        $this->assertSame(Notice::URGENTE, $avisos->first()->nivel);
    }

    public function test_un_embarque_sin_contenedores_sale_en_la_bandeja(): void
    {
        $this->bookingVivo(1);

        $this->assertCount(1, $this->avisos()->where('grupo', 'sin_carga'));
    }

    public function test_una_factura_sin_timbrar_sale_en_la_bandeja(): void
    {
        $this->bookingVivo(1);
        DB::table('transaction')->insert([[
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'account' => 1,
            'tran_number' => 'F-1', 'tran_date' => now()->toDateString(), 'invoice_type' => 1,
        ]]);

        $avisos = $this->avisos()->where('grupo', 'timbrado');

        $this->assertCount(1, $avisos);
        $this->assertSame('F-1', $avisos->first()->titulo);
    }

    public function test_una_factura_ya_timbrada_no_sale(): void
    {
        $this->bookingVivo(1);
        DB::table('transaction')->insert([[
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'account' => 1,
            'tran_number' => 'F-1', 'tran_date' => now()->toDateString(), 'invoice_type' => 1, 'seal' => 'UUID-1',
        ]]);

        $this->assertCount(0, $this->avisos()->where('grupo', 'timbrado'));
    }

    /**
     * El recorte que hace útil la bandeja: un embarque cerrado, o de hace años,
     * no molesta a nadie aunque tenga tareas sin marcar.
     */
    public function test_los_embarques_viejos_o_cerrados_no_hacen_ruido(): void
    {
        $this->bookingVivo(1, ['loading_EDT' => now()->subYears(3)->toDateString()]);
        $this->bookingVivo(2, ['locked' => 1]);

        $this->assertCount(0, $this->avisos());
    }

    public function test_una_tarea_vencida_de_un_embarque_vivo_sale_en_la_bandeja(): void
    {
        $this->bookingVivo(1);
        DB::table('containers')->insert([['container_ID' => 1, 'booking' => 1, 'container_type' => 1, 'quantity' => 1]]);
        DB::table('transaction')->insert([[
            'transc_id' => 1, 'booking' => 1, 'tran_type' => 0, 'customer' => 1, 'account' => 1,
            'tran_number' => 'F-1', 'tran_date' => now()->toDateString(), 'invoice_type' => 1, 'seal' => 'UUID-1',
        ]]);
        DB::table('booking_continuity')->insert([[
            'cont_id' => 1, 'booking' => 1, 'SI_date' => now()->subDays(2)->toDateTimeString(),
        ]]);
        DB::table('check_list')->insert([['check_id' => 1, 'booking' => 1]]);

        $avisos = $this->avisos()->where('grupo', 'tareas');

        $this->assertCount(1, $avisos);
        $this->assertSame('SI', $avisos->first()->titulo);
    }

    public function test_la_pantalla_agrupa_y_filtra(): void
    {
        $this->bookingVivo(1);

        Livewire::actingAs($this->usuario())
            ->test(Notifications::class)
            ->assertSee('BK-1')
            ->set('grupo', 'pagos')
            ->assertDontSee('BK-1');
    }

    public function test_sin_pendientes_lo_dice(): void
    {
        Livewire::actingAs($this->usuario())
            ->test(Notifications::class)
            ->assertSee('No hay nada pendiente.');
    }

    /** La campana enseña el mismo número que la bandeja. */
    public function test_la_campana_cuenta_lo_mismo_que_la_bandeja(): void
    {
        // Un booking recién cargado sin contenedores y sin factura: dos avisos.
        $this->bookingVivo(1);

        $feed = app(NoticeFeed::class);

        $this->assertSame($feed->all()->count(), $feed->count());
        $this->assertSame(2, $feed->count());

        $this->actingAs($this->usuario())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('notifications'));
    }

    /**
     * Lo que se guarda en caché tiene que poder releerse con el driver que
     * serializa: la campana se pinta en todas las páginas y un objeto roto ahí
     * tumbaría el sistema entero. Si se cachearan modelos de Eloquent, la
     * segunda lectura vendría como `__PHP_Incomplete_Class` y esto fallaría.
     */
    public function test_la_cache_de_la_bandeja_se_puede_releer(): void
    {
        $ruta = storage_path('framework/testing/cache-avisos-'.getmypid());

        config(['cache.default' => 'file', 'cache.stores.file.path' => $ruta]);

        $this->bookingVivo(1);

        $primera = app(NoticeFeed::class)->all()->map(fn ($aviso) => $aviso->toArray())->all();
        $segunda = app(NoticeFeed::class)->all()->map(fn ($aviso) => $aviso->toArray())->all();

        $this->assertSame($primera, $segunda);
        $this->assertNotSame([], $primera);

        File::deleteDirectory($ruta);
    }
}
