<?php

namespace Tests\Feature;

use App\Actions\Demo\RellenaDatosDemo;
use App\Livewire\Settings;
use App\Models\User;
use Database\Seeders\Perfiles\PerfilDemo;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * El botón que rellena la demostración.
 *
 * Lo que se prueba aquí no es que llene bien —eso lo miran las pruebas del grupo
 * `demo` contra la base sembrada—, sino que **no llene donde no debe**: es un
 * botón que vacía la base entera, y en la instalación de un cliente no puede
 * existir ni respondiendo a mano.
 */
class RellenaDemoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();
    }

    private function jefa(int $rol = User::ROLE_SUPER_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'jefa'.$rol, 'password' => 'secreto-de-prueba',
            'role' => $rol, 'access' => User::ACCESS_INTERNAL, 'status' => 1,
        ]);
    }

    /** De fábrica está apagado: se enciende a mano en la instalación de demostración. */
    public function test_de_fabrica_no_esta_permitido(): void
    {
        config(['marca.demo' => false]);

        $this->assertFalse(RellenaDatosDemo::permitido());
    }

    public function test_sin_marca_demo_la_accion_se_niega(): void
    {
        config(['marca.demo' => false]);

        $this->expectException(RuntimeException::class);

        app(RellenaDatosDemo::class)('terrestre');
    }

    /** Ni por la pantalla: no basta con que el botón no se pinte. */
    public function test_sin_marca_demo_la_pantalla_tampoco_rellena(): void
    {
        config(['marca.demo' => false]);

        Livewire::actingAs($this->jefa())->test(Settings::class)
            ->call('rellenar', 'terrestre')
            ->assertForbidden();
    }

    public function test_el_apartado_solo_sale_en_una_instalacion_de_demostracion(): void
    {
        config(['marca.demo' => false]);

        Livewire::actingAs($this->jefa())->test(Settings::class)
            ->assertDontSee(__('Datos de demostración'));

        config(['marca.demo' => true]);

        Livewire::actingAs($this->jefa())->test(Settings::class)
            ->assertSee(__('Datos de demostración'))
            ->assertSee(__('Autotransporte de carga (México)'));
    }

    /** Solo el super administrador, como el resto de los ajustes. */
    public function test_un_administrador_normal_no_entra_a_ajustes(): void
    {
        config(['marca.demo' => true]);

        Livewire::actingAs($this->jefa(User::ROLE_ADMIN))->test(Settings::class)->assertForbidden();
    }

    public function test_una_vertical_que_no_existe_se_niega(): void
    {
        config(['marca.demo' => true]);

        $this->expectException(RuntimeException::class);

        app(RellenaDatosDemo::class)('aereo');
    }

    /** Cada vertical apunta a un perfil de datos que de verdad existe. */
    public function test_las_verticales_apuntan_a_perfiles_reales(): void
    {
        foreach (RellenaDatosDemo::VERTICALES as $vertical => $config) {
            $this->assertNotEmpty($config['modalidades'], "La vertical «{$vertical}» no elige transporte.");
            $this->assertInstanceOf(PerfilDemo::class, PerfilDemo::porNombre($config['perfil']));
        }
    }
}
