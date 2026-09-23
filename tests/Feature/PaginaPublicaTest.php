<?php

namespace Tests\Feature;

use App\Livewire\DemoRequests;
use App\Livewire\Home;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * La página pública y las solicitudes de demostración.
 *
 * Antes la raíz mandaba directo al login: quien llegaba al dominio no veía nada
 * de lo que hace el sistema.
 */
class PaginaPublicaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        RateLimiter::clear('demo:127.0.0.1');

        // La portada pública se prueba encendida, sin depender del .env local
        // (que en esta máquina puede estar apagado para replicar a un cliente).
        config(['marca.landing' => true]);
    }

    private function admin(int $rol = User::ROLE_SUPER_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'jefa'.$rol, 'password' => 'secreto-de-prueba',
            'role' => $rol, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);
    }

    public function test_la_raiz_enseña_la_pagina_publica(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(__('Solicitar una demostración'))
            ->assertSee(__('Qué incluye'));
    }

    /** Sin portada (instalación de cliente): la raíz va directo al login. */
    public function test_sin_portada_la_raiz_va_al_login(): void
    {
        config(['marca.landing' => false]);

        $this->get('/')->assertRedirect(route('login'));
    }

    /** Sin portada tampoco existe la pantalla de solicitudes de demo. */
    public function test_sin_portada_no_hay_solicitudes_de_demo(): void
    {
        config(['marca.landing' => false]);

        $this->actingAs($this->admin())->get(route('demo-requests'))->assertNotFound();
    }

    /** Quien ya entró no ve la página de venta: se va a lo suyo. */
    public function test_con_sesion_no_se_ve_la_pagina_publica(): void
    {
        $this->actingAs($this->admin())->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_una_solicitud_se_guarda(): void
    {
        Livewire::test(Home::class)
            ->set('nombre', 'Ana Prospecto')
            ->set('empresa', 'Fletes del Norte')
            ->set('correo', 'ana@ejemplo.test')
            ->set('telefono', '33 1234 5678')
            ->set('mensaje', 'Quiero ver la parte de costos')
            ->call('solicitar')
            ->assertHasNoErrors()
            ->assertSet('enviada', true);

        $fila = DB::table('solicitud_demo')->first();

        $this->assertSame('Ana Prospecto', $fila->nombre);
        $this->assertSame('ana@ejemplo.test', $fila->correo);
        $this->assertSame(0, (int) $fila->atendida);
    }

    public function test_el_correo_y_el_nombre_son_obligatorios(): void
    {
        Livewire::test(Home::class)->call('solicitar')->assertHasErrors(['nombre', 'correo']);

        Livewire::test(Home::class)
            ->set('nombre', 'Ana')->set('correo', 'esto-no-es-un-correo')
            ->call('solicitar')->assertHasErrors('correo');
    }

    /**
     * La trampa para robots: se responde como si todo hubiera ido bien y no se
     * guarda nada. Decirle al robot que lo detectaste solo le enseña a evitarlo.
     */
    public function test_un_robot_no_deja_basura(): void
    {
        Livewire::test(Home::class)
            ->set('nombre', 'Robot')->set('correo', 'robot@ejemplo.test')
            ->set('sitioWeb', 'https://spam.test')
            ->call('solicitar')
            ->assertSet('enviada', true);

        $this->assertSame(0, DB::table('solicitud_demo')->count());
    }

    /** Y un mismo origen no puede llenar el buzón. */
    public function test_hay_un_tope_por_origen(): void
    {
        foreach (range(1, 4) as $i) {
            Livewire::test(Home::class)
                ->set('nombre', 'Ana '.$i)->set('correo', "ana{$i}@ejemplo.test")
                ->call('solicitar');
        }

        $this->assertSame(3, DB::table('solicitud_demo')->count());
    }

    public function test_solo_el_super_administrador_lee_las_solicitudes(): void
    {
        $this->actingAs($this->admin(User::ROLE_ADMIN));

        Livewire::test(DemoRequests::class)->assertForbidden();
    }

    public function test_el_super_administrador_las_ve_y_las_marca(): void
    {
        DB::table('solicitud_demo')->insert([
            'solicitud_id' => 1, 'nombre' => 'Ana Prospecto', 'correo' => 'ana@ejemplo.test',
            'atendida' => 0, 'created_at' => now(),
        ]);

        $this->actingAs($this->admin());

        Livewire::test(DemoRequests::class)
            ->assertSee('Ana Prospecto')
            ->call('alternar', 1);

        $this->assertSame(1, (int) DB::table('solicitud_demo')->value('atendida'));
    }
}
