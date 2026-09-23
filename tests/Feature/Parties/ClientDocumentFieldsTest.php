<?php

namespace Tests\Feature\Parties;

use App\Livewire\Parties\PartyForm;
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

        // Aquí se prueban los documentos, no los datos del CFDI: sin timbrado la
        // ficha no exige RFC, dirección, código postal ni régimen.
        config(['timbrado.habilitado' => false]);

        DB::table('file_fields')->insert([
            ['field_id' => 1, 'field' => 'entrusts_letter_file', 'label' => 'Entrust Letter', 'default' => 1],
            ['field_id' => 2, 'field' => 'petition_file', 'label' => 'Petition', 'default' => 1],
            ['field_id' => 3, 'field' => 'coa_file', 'label' => 'COA', 'default' => 0],
        ]);
        DB::table('client')->insert([['client_id' => 5, 'fullName' => 'Frialsa']]);
    }

    private function pantalla(int $party = 5, int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs(User::forceCreate([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]));

        return Livewire::test(PartyForm::class, ['mode' => 'client', 'party' => $party]);
    }

    public function test_se_eligen_al_editar_el_cliente(): void
    {
        $this->pantalla()
            ->set('documentFields', ['1', '3'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            [1, 3],
            DB::table('fields_by_client')->where('client_id', 5)->orderBy('field_id')
                ->pluck('field_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    /** `fields_by_client` no tiene llave foránea: un id que no existe se rechaza aquí. */
    public function test_un_documento_inexistente_no_se_acepta(): void
    {
        $this->pantalla()
            ->set('documentFields', ['1', '99'])
            ->call('save')
            ->assertHasErrors(['documentFields.1' => 'exists']);

        $this->assertSame(0, DB::table('fields_by_client')->count());
    }

    public function test_desmarcar_los_quita_y_no_toca_los_demas(): void
    {
        DB::table('fields_by_client')->insert([
            ['client_id' => 5, 'field_id' => 1],
            ['client_id' => 5, 'field_id' => 2],
            ['client_id' => 9, 'field_id' => 1],
        ]);

        $this->pantalla()
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

        $this->pantalla()->assertSet('documentFields', ['2']);
    }

    /** Como `Client::generateFields()` en Yii2: los marcados «por omisión» vienen elegidos. */
    public function test_un_cliente_nuevo_trae_marcados_los_documentos_por_omision(): void
    {
        $this->actingAs(User::forceCreate([
            'username' => 'admin3', 'password' => 'secreto-de-prueba', 'role' => User::ROLE_ADMIN, 'status' => 1,
        ]));

        Livewire::test(PartyForm::class, ['mode' => 'client', 'party' => null])
            ->assertSet('documentFields', ['1', '2'])
            ->set('form.fullName', 'Cliente nuevo')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([1, 2], DB::table('fields_by_client')->orderBy('field_id')
            ->pluck('field_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_el_proveedor_no_los_pide(): void
    {
        DB::table('provider')->insert([['provider_id' => 3, 'fullName' => 'Naviera', 'type_id' => 1]]);

        $this->actingAs(User::forceCreate([
            'username' => 'admin2', 'password' => 'secreto-de-prueba', 'role' => User::ROLE_ADMIN, 'status' => 1,
        ]));

        Livewire::test(PartyForm::class, ['mode' => 'provider', 'party' => 3])
            ->set('documentFields', ['1'])
            ->call('save');

        $this->assertSame(0, DB::table('fields_by_client')->count());
    }
}
