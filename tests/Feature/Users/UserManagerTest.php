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
        return User::forceCreate([
            'name' => 'Super', 'username' => 'super.admin', 'email' => 'super@ejemplo.com',
            'password' => 'secreto-de-prueba', 'role' => User::ROLE_SUPER_ADMIN, 'status' => 1,
        ]);
    }

    private function pantalla(?User $como = null): Testable
    {
        $this->actingAs($como ?? $this->superAdmin());

        return Livewire::test(UserManager::class);
    }

    private function admin(): User
    {
        return User::forceCreate([
            'name' => 'Admin', 'username' => 'admin.normal', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_ADMIN, 'status' => 1,
        ]);
    }

    public function test_un_usuario_normal_no_entra(): void
    {
        $usuario = User::forceCreate([
            'name' => 'Juan', 'username' => 'juan.normal', 'password' => 'secreto-de-prueba',
            'role' => User::ROLE_USER, 'status' => 1,
        ]);

        $this->actingAs($usuario);

        Livewire::test(UserManager::class)->assertForbidden();
    }

    /** Como en Yii2: un administrador normal tampoco entra, ni por la ruta ni por Livewire. */
    public function test_un_administrador_normal_recibe_403(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('users'))->assertForbidden();
        Livewire::test(UserManager::class)->assertForbidden();
    }

    public function test_el_super_administrador_entra_por_la_ruta(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get(route('users'))->assertOk()->assertSee(__('Usuarios y accesos'));
    }

    // ------------------------------------------------------- Auditoría

    public function test_al_crear_se_registra_quien_lo_hizo(): void
    {
        $dueno = $this->superAdmin();

        $this->pantalla($dueno)
            ->call('create')
            ->set('username', 'karina')
            ->set('email', 'karina@ejemplo.com')
            ->set('password', 'contrasena-larga')
            ->set('passwordConfirmation', 'contrasena-larga')
            ->call('save')
            ->assertHasNoErrors();

        $creado = User::where('username', 'karina')->first();

        $this->assertSame($dueno->usr_id, (int) $creado->created_by);
        $this->assertSame($dueno->usr_id, (int) $creado->modified_by);
    }

    public function test_al_editar_se_conserva_quien_lo_creo_y_se_anota_quien_lo_toco(): void
    {
        $dueno = $this->superAdmin();
        $otro = User::forceCreate([
            'username' => 'karina', 'email' => 'karina@ejemplo.com', 'password' => 'x',
            'role' => User::ROLE_USER, 'status' => 1, 'created_by' => 77,
        ]);

        $this->pantalla($dueno)->call('edit', $otro->usr_id)->set('name', 'Karina')->call('save');

        $otro->refresh();

        $this->assertSame(77, (int) $otro->created_by);
        $this->assertSame($dueno->usr_id, (int) $otro->modified_by);
    }

    public function test_el_listado_enseña_quien_creo_y_modifico_y_la_hora_del_ultimo_ingreso(): void
    {
        $dueno = $this->superAdmin();

        User::forceCreate([
            'username' => 'karina', 'email' => 'karina@ejemplo.com', 'password' => 'x',
            'role' => User::ROLE_USER, 'status' => 1,
            'created_by' => $dueno->usr_id, 'modified_by' => $dueno->usr_id,
            'last_login' => '2026-09-21 14:35:00',
        ]);

        $this->pantalla($dueno)
            ->assertSee('karina@ejemplo.com')
            ->assertSee('21/09/2026 14:35')
            ->assertSeeInOrder(['karina', 'super.admin', 'super.admin']);
    }

    public function test_crear_un_usuario_interno(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('name', 'Karina')
            ->set('username', 'karina')
            ->set('email', 'karina@ejemplo.com')
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
            ->set('email', 'otro@ejemplo.com')
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
            ->set('email', 'nuevo@ejemplo.com')
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
            ->set('email', 'portal@ejemplo.com')
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
            ->set('email', 'portal@ejemplo.com')
            ->set('access', (string) UserManager::ACCESS_CLIENT)
            ->set('userRole', (string) User::ROLE_CLIENT_READONLY)
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
        $otro = User::forceCreate([
            'name' => 'Karina', 'username' => 'karina', 'email' => 'karina@ejemplo.com',
            'password' => 'contrasena-original', 'role' => User::ROLE_USER, 'status' => 1,
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
        $otro = User::forceCreate([
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
        $otro = User::forceCreate([
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
    // ------------------------------------------------- Roles de portal (A20)

    /** Cuenta del portal de cliente con rol 13 (editor), como las 81 que hay en la base. */
    private function cuentaDePortal(int $rol = User::ROLE_CLIENT_EDITOR): User
    {
        return User::forceCreate([
            'username' => 'portal.editor', 'email' => 'editor@ejemplo.com', 'password' => 'x',
            'role' => $rol, 'access' => User::ACCESS_CLIENT, 'client_id' => 1, 'status' => 1,
        ]);
    }

    public function test_editar_una_cuenta_de_portal_sin_tocar_el_rol_guarda_bien(): void
    {
        $cuenta = $this->cuentaDePortal();

        $this->pantalla()
            ->call('edit', $cuenta->usr_id)
            ->set('name', 'Editor del cliente')
            ->call('save')
            ->assertHasNoErrors();

        $cuenta->refresh();

        $this->assertSame('Editor del cliente', $cuenta->name);
        $this->assertSame(User::ROLE_CLIENT_EDITOR, (int) $cuenta->role);
    }

    public function test_un_acceso_de_cliente_no_acepta_un_rol_interno(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('username', 'portal.admin')
            ->set('email', 'portal@ejemplo.com')
            ->set('access', (string) UserManager::ACCESS_CLIENT)
            ->set('partyId', '1')
            ->set('userRole', (string) User::ROLE_ADMIN)
            ->set('password', 'contrasena-larga')
            ->set('passwordConfirmation', 'contrasena-larga')
            ->call('save')
            ->assertHasErrors('userRole');

        $this->assertNull(User::where('username', 'portal.admin')->first());
    }

    /** Al cambiar el acceso en el formulario, el rol pasa al primero de la lista que toca. */
    public function test_al_cambiar_el_acceso_cambia_la_lista_de_roles(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('access', (string) UserManager::ACCESS_PROVIDER)
            ->assertSet('userRole', (string) User::ROLE_PROVIDER_CUSTOMS_BROKER)
            ->assertSeeHtml('<option value="'.User::ROLE_PROVIDER_CARRIER.'" >'.__('Transportista').'</option>');
    }

    public function test_el_listado_pinta_la_etiqueta_del_rol_de_portal(): void
    {
        $this->cuentaDePortal(User::ROLE_CLIENT_CUSTOMS_BROKER);

        $this->pantalla()->assertSee(__('Agente aduanal del cliente'));
    }

    /** Los roles de portal no dan permisos internos. */
    public function test_los_roles_de_portal_no_son_administradores(): void
    {
        foreach (User::clientRoles() + User::providerRoles() as $rol => $etiqueta) {
            $cuenta = (new User)->forceFill(['role' => $rol, 'access' => User::ACCESS_CLIENT]);

            $this->assertFalse($cuenta->isAdmin(), "El rol {$rol} ({$etiqueta}) no debe ser administrador.");
            $this->assertTrue($cuenta->isPortal());
        }
    }

    // --------------------------------------------- Baja y sesión (A19)

    public function test_dar_de_baja_limpia_el_token_de_recordar(): void
    {
        $otro = User::forceCreate([
            'username' => 'karina', 'password' => 'x', 'role' => User::ROLE_USER, 'status' => 1,
            'remember_token' => 'token-de-la-cookie',
        ]);

        $this->pantalla()->call('toggleActive', $otro->usr_id);

        $this->assertNull($otro->refresh()->remember_token);
    }

    // ------------------------------------------------- Uno mismo (extra)

    public function test_nadie_se_da_de_baja_a_si_mismo_desde_el_formulario(): void
    {
        $super = $this->superAdmin();

        $this->pantalla($super)
            ->call('edit', $super->usr_id)
            ->set('active', false)
            ->call('save')
            ->assertHasErrors('active');

        $this->assertSame(1, (int) $super->refresh()->status);
    }

    public function test_nadie_se_cambia_el_rol_a_si_mismo(): void
    {
        $super = $this->superAdmin();

        $this->pantalla($super)
            ->call('edit', $super->usr_id)
            ->set('userRole', (string) User::ROLE_USER)
            ->call('save')
            ->assertHasErrors('userRole');

        $this->assertTrue($super->refresh()->isSuperAdmin());
    }

    public function test_al_editarse_uno_mismo_no_aparece_la_casilla_de_activo(): void
    {
        $super = $this->superAdmin();

        $this->pantalla($super)->call('edit', $super->usr_id)->assertDontSeeHtml('wire:model="active"');
    }

    // ------------------------------------------------- Acceso sin definir

    /** Hay una cuenta real así: entra como interna, pero el grid lo señala. */
    public function test_una_cuenta_sin_acceso_entra_como_interna_y_el_listado_lo_señala(): void
    {
        $cuenta = User::forceCreate([
            'username' => 'sin.acceso', 'password' => 'x', 'role' => User::ROLE_USER, 'access' => null, 'status' => 1,
        ]);

        $this->assertTrue($cuenta->isInternal());
        $this->assertTrue($cuenta->sinAccesoDefinido());

        $this->pantalla()->assertSee(__('Sin acceso definido'));
    }

    public function test_al_editar_una_cuenta_sin_acceso_se_le_asigna_interno(): void
    {
        $cuenta = User::forceCreate([
            'username' => 'sin.acceso', 'email' => 'sin@ejemplo.com', 'password' => 'x',
            'role' => User::ROLE_USER, 'access' => null, 'status' => 1,
        ]);

        $this->pantalla()
            ->call('edit', $cuenta->usr_id)
            ->assertSet('access', (string) UserManager::ACCESS_INTERNAL)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(User::ACCESS_INTERNAL, (int) $cuenta->refresh()->access);
    }

    // ------------------------------------------------------ Correo (extra)

    public function test_el_correo_es_obligatorio(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('username', 'sin.correo')
            ->set('password', 'contrasena-larga')
            ->set('passwordConfirmation', 'contrasena-larga')
            ->call('save')
            ->assertHasErrors(['email' => 'required']);
    }

    public function test_un_correo_nuevo_debe_tener_forma_de_correo(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('username', 'nuevo')
            ->set('email', 'esto-no-es-un-correo')
            ->set('password', 'contrasena-larga')
            ->set('passwordConfirmation', 'contrasena-larga')
            ->call('save')
            ->assertHasErrors(['email' => 'email']);
    }

    /** La tabla heredada guarda en `email` valores que no son correos; eso no debe bloquear la edición. */
    public function test_un_correo_heredado_sin_forma_no_impide_editar(): void
    {
        $otro = User::forceCreate([
            'username' => 'elymaersk', 'email' => 'elymaersk', 'password' => 'x',
            'role' => User::ROLE_USER, 'status' => 1,
        ]);

        $this->pantalla()
            ->call('edit', $otro->usr_id)
            ->set('name', 'Ely')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Ely', $otro->refresh()->name);
    }

    // ------------------------------------------------ Etapa 3 (bajas)

    public function test_el_cliente_ligado_tiene_que_existir(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('username', 'portal.cliente')
            ->set('email', 'portal@ejemplo.com')
            ->set('access', (string) UserManager::ACCESS_CLIENT)
            ->set('userRole', (string) User::ROLE_CLIENT_READONLY)
            ->set('partyId', '999')
            ->set('password', 'contrasena-larga')
            ->set('passwordConfirmation', 'contrasena-larga')
            ->call('save')
            ->assertHasErrors(['partyId' => 'exists']);
    }

    /** El id del cliente 1 no vale como proveedor: cada acceso mira su catálogo. */
    public function test_el_proveedor_ligado_se_busca_entre_los_proveedores(): void
    {
        $this->pantalla()
            ->call('create')
            ->set('username', 'portal.proveedor')
            ->set('email', 'proveedor@ejemplo.com')
            ->set('access', (string) UserManager::ACCESS_PROVIDER)
            ->set('userRole', (string) User::ROLE_PROVIDER_CARRIER)
            ->set('partyId', '1')
            ->set('password', 'contrasena-larga')
            ->set('passwordConfirmation', 'contrasena-larga')
            ->call('save')
            ->assertHasErrors(['partyId' => 'exists']);
    }

    /**
     * Quien opera es super administrador, así que el «último» solo puede ser
     * otro cuando la sesión de quien opera ya está dada de baja (entre su baja
     * y su siguiente clic). Se arma así para probar la regla por sí sola.
     */
    private function ultimoSuperAdminYOperadorDadoDeBaja(): array
    {
        $operador = User::forceCreate([
            'username' => 'operador.baja', 'email' => 'baja@ejemplo.com', 'password' => 'x',
            'role' => User::ROLE_SUPER_ADMIN, 'status' => 0,
        ]);

        return [$operador, $this->superAdmin()];
    }

    public function test_el_ultimo_super_admin_activo_no_se_da_de_baja_desde_el_formulario(): void
    {
        [$operador, $ultimo] = $this->ultimoSuperAdminYOperadorDadoDeBaja();

        $this->pantalla($operador)
            ->call('edit', $ultimo->usr_id)
            ->set('active', false)
            ->call('save')
            ->assertHasErrors('active');

        $this->assertSame(1, (int) $ultimo->refresh()->status);
    }

    public function test_el_ultimo_super_admin_activo_no_se_degrada(): void
    {
        [$operador, $ultimo] = $this->ultimoSuperAdminYOperadorDadoDeBaja();

        $this->pantalla($operador)
            ->call('edit', $ultimo->usr_id)
            ->set('userRole', (string) User::ROLE_ADMIN)
            ->call('save')
            ->assertHasErrors('userRole');

        $this->assertSame(User::ROLE_SUPER_ADMIN, (int) $ultimo->refresh()->role);
    }

    public function test_el_ultimo_super_admin_activo_no_se_da_de_baja_desde_el_listado(): void
    {
        [$operador, $ultimo] = $this->ultimoSuperAdminYOperadorDadoDeBaja();

        $this->pantalla($operador)->call('toggleActive', $ultimo->usr_id)->assertStatus(422);

        $this->assertSame(1, (int) $ultimo->refresh()->status);
    }

    public function test_con_otro_super_admin_activo_si_se_puede_dar_de_baja(): void
    {
        $otro = User::forceCreate([
            'username' => 'otro.super', 'email' => 'otro@ejemplo.com', 'password' => 'x',
            'role' => User::ROLE_SUPER_ADMIN, 'status' => 1,
        ]);

        $this->pantalla()->call('toggleActive', $otro->usr_id);

        $this->assertSame(0, (int) $otro->refresh()->status);
    }

    /** Rol, acceso, estado y cliente o proveedor ligado no entran por asignación masiva. */
    public function test_los_campos_de_permiso_no_son_asignables_en_masa(): void
    {
        $cuenta = new User([
            'username' => 'colado', 'role' => User::ROLE_SUPER_ADMIN, 'access' => User::ACCESS_INTERNAL,
            'status' => 1, 'client_id' => 1, 'provider_id' => 1,
        ]);

        $this->assertSame(['username' => 'colado'], $cuenta->getAttributes());
    }
}
