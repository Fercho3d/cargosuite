<?php

namespace Tests\Feature\Parties;

use App\Livewire\Parties\PartyManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/**
 * Qué documentos se le piden a cada cliente (`fields_by_client`).
 *
 * Es lo que decide qué campos ofrece el booking para subir papeles: sin esto,
 * un cliente nuevo no tiene dónde recibirlos.
 */
class ClientDocumentFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('file_fields')->insert([
            ['field_id' => 1, 'field' => 'entrusts_letter_file', 'label' => 'Entrust Letter', 'default' => 1],
            ['field_id' => 2, 'field' => 'petition_file', 'label' => 'Petition', 'default' => 1],
            ['field_id' => 3, 'field' => 'coa_file', 'label' => 'COA', 'default' => 0],
        ]);
        DB::table('client')->insert([['client_id' => 5, 'fullName' => 'Frialsa']]);
    }

    private function pantalla(int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs(User::create([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]));

        return Livewire::test(PartyManager::class, ['mode' => 'client']);
    }

    public function test_se_eligen_al_editar_el_cliente(): void
    {
        $this->pantalla()
            ->call('edit', 5)
            ->set('documentFields', ['1', '3'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            [1, 3],
            DB::table('fields_by_client')->where('client_id', 5)->orderBy('field_id')
                ->pluck('field_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_desmarcar_los_quita_y_no_toca_los_demas(): void
    {
        DB::table('fields_by_client')->insert([
            ['client_id' => 5, 'field_id' => 1],
            ['client_id' => 5, 'field_id' => 2],
            ['client_id' => 9, 'field_id' => 1],
        ]);

        $this->pantalla()
            ->call('edit', 5)
            ->set('documentFields', ['2'])
            ->call('save');

        $this->assertSame([2], DB::table('fields_by_client')->where('client_id', 5)
            ->pluck('field_id')->map(fn ($id) => (int) $id)->all());
        // El otro cliente se queda como estaba.
        $this->assertSame(1, DB::table('fields_by_client')->where('client_id', 9)->count());
    }

    public function test_al_editar_llegan_marcados_los_que_ya_tiene(): void
    {
        DB::table('fields_by_client')->insert([['client_id' => 5, 'field_id' => 2]]);

        $this->pantalla()->call('edit', 5)->assertSet('documentFields', ['2']);
    }

    public function test_el_proveedor_no_los_pide(): void
    {
        DB::table('provider')->insert([['provider_id' => 3, 'fullName' => 'Naviera', 'type_id' => 1]]);

        $this->actingAs(User::create([
            'username' => 'admin2', 'password' => 'secreto-de-prueba', 'role' => User::ROLE_ADMIN, 'status' => 1,
        ]));

        Livewire::test(PartyManager::class, ['mode' => 'provider'])
            ->call('edit', 3)
            ->set('documentFields', ['1'])
            ->call('save');

        $this->assertSame(0, DB::table('fields_by_client')->count());
    }
}
