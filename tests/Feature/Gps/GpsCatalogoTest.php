<?php

namespace Tests\Feature\Gps;

use App\Livewire\Catalogs\CatalogManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/** El catálogo de dispositivos GPS: a qué unidad va cada equipo y cómo se conecta. */
class GpsCatalogoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
        config(['marca.modalidades' => 'terrestre']);

        DB::table('unidad')->insert(['unidad_id' => 3, 'numero' => 'T-103', 'tipo' => 'tractor', 'activo' => 1]);
        DB::table('gps_dispositivo')->insert(['identificador' => '356307042441013', 'unidad_id' => 3, 'protocolo' => 'queclink', 'activo' => 1]);
    }

    private function catalogo(): Testable
    {
        $admin = User::forceCreate([
            'username' => 'jefa', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_ADMIN, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);

        return Livewire::actingAs($admin)->test(CatalogManager::class, ['catalog' => 'gps']);
    }

    /** La lista dice la unidad y la marca, no su número interno ni su clave. */
    public function test_la_lista_ensena_la_unidad_y_la_marca_por_su_nombre(): void
    {
        $this->catalogo()->assertSeeInOrder(['356307042441013', 'T-103', 'Queclink (GV300, GV350)']);
    }

    public function test_trae_las_instrucciones_para_conectar_un_equipo(): void
    {
        $this->catalogo()->assertSee(__('Cómo conectar un GPS'));
    }
}
