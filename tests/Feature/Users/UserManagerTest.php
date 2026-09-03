<?php

namespace Tests\Feature\Users;

use App\Livewire\Users\UserManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Altas y accesos de usuarios.
 *
 * Solo el super administrador entra, igual que en el sistema original.
 */
class UserManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
    }

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'Super', 'username' => 'super.admin', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_SUPER_ADMIN, 'status' => 1,
        ]);
    }

    private function pantalla(?User $como = null): Testable
    {
        $this->actingAs($como ?? $this->superAdmin());

        return Livewire::test(UserManager::class);
    }

    public function test_solo_el_super_administrador_entra(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'username' => 'admin.normal', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_ADMIN, 'status' => 1,
        ]);

        $this->actingAs($admin);

        Livewire::test(UserManager::class)->assertForbidden();
    }

    public function test_crear_un_usuario_interno(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('name', 'Karina')
            ->set('username', 'karina')
            ->set('userRole', (string) User::ROLE_USER)
            ->set('password', 'contrasena-larga')
            ->set('passwordConfirmation', 'contrasena-larga')
            ->call('save')
            ->assertHasNoErrors();

        $creado = User::where('username', 'karina')->first();

        $this->assertNotNull($creado);
        $this->assertSame(1, (int) $creado->status);
        $this->assertTrue(Hash::check('contrasena-larga', $creado->password), 'La contraseña debe guardarse cifrada.');
    }

    public function test_el_usuario_no_se_repite(): void
    {
        $this->superAdmin();

        $this->pantalla()
            ->call('create')
            ->set('username', 'super.admin')
            ->set('password', 'contrasena-larga')
            ->set('passwordConfirmation', 'contrasena-larga')
            ->call('save')
            ->assertHasErrors('username');
    }

    public function test_las_contrasenas_deben_coincidir(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('username', 'nuevo')
            ->set('password', 'contrasena-larga')
            ->set('passwordConfirmation', 'otra-distinta')
            ->call('save')
            ->assertHasErrors('password');
    }

    /** Un acceso de portal no tiene sentido sin el cliente o proveedor al que pertenece. */
    public function test_el_acceso_de_portal_exige_a_quien_pertenece(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('username', 'portal.cliente')
            ->set('access', (string) UserManager::ACCESS_CLIENT)
            ->set('password', 'contrasena-larga')
            ->set('passwordConfirmation', 'contrasena-larga')
            ->call('save')
            ->assertHasErrors('partyId');
    }

    public function test_un_acceso_de_portal_queda_ligado_a_su_cliente(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('username', 'portal.cliente')
            ->set('access', (string) UserManager::ACCESS_CLIENT)
            ->set('partyId', '1')
            ->set('password', 'contrasena-larga')
            ->set('passwordConfirmation', 'contrasena-larga')
            ->call('save')
            ->assertHasNoErrors();

        $creado = User::where('username', 'portal.cliente')->first();

        $this->assertSame(1, (int) $creado->client_id);
        $this->assertNull($creado->provider_id);
    }

    public function test_editar_sin_tocar_la_contrasena_la_deja_igual(): void
    {
        $otro = User::create([
            'name' => 'Karina', 'username' => 'karina', 'password' => 'contrasena-original',
            'role' => User::ROLE_USER, 'status' => 1,
        ]);

        $this->pantalla()
            ->call('edit', $otro->usr_id)
            ->set('name', 'Karina Pérez')
            ->call('save')
            ->assertHasNoErrors();

        $otro->refresh();

        $this->assertSame('Karina Pérez', $otro->name);
        $this->assertTrue(Hash::check('contrasena-original', $otro->password));
    }

    public function test_cambiar_la_contrasena(): void
    {
        $otro = User::create([
            'username' => 'karina', 'password' => 'contrasena-original',
            'role' => User::ROLE_USER, 'status' => 1,
        ]);

        $this->pantalla()
            ->call('startPasswordChange', $otro->usr_id)
            ->set('password', 'contrasena-nueva')
            ->set('passwordConfirmation', 'contrasena-nueva')
            ->call('changePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('contrasena-nueva', $otro->refresh()->password));
    }

    /** No se borran: la tabla la referencian las columnas de auditoría del sistema. */
    public function test_dar_de_baja_y_reactivar(): void
    {
        $otro = User::create([
            'username' => 'karina', 'password' => 'x', 'role' => User::ROLE_USER, 'status' => 1,
        ]);

        $this->pantalla()->call('toggleActive', $otro->usr_id);
        $this->assertSame(0, (int) $otro->refresh()->status);

        $this->pantalla()->call('toggleActive', $otro->usr_id);
        $this->assertSame(1, (int) $otro->refresh()->status);
    }

    public function test_nadie_se_da_de_baja_a_si_mismo(): void
    {
        $super = $this->superAdmin();

        $this->pantalla($super)->call('toggleActive', $super->usr_id)->assertStatus(422);

        $this->assertSame(1, (int) $super->refresh()->status);
    }
}
