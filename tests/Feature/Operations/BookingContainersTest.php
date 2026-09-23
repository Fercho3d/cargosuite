<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operations\BookingDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CoreSchema;
use Tests\TestCase;

/** Contenedores de un booking: alta, edición y baja desde el detalle. */
class BookingContainersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoreSchema::create();
        CoreSchema::createUsers();

        DB::table('client')->insert([['client_id' => 1, 'fullName' => 'Cliente Uno']]);
        DB::table('container_types')->insert([['contType_id' => 1, 'container_name' => '40 HC']]);
        DB::table('booking')->insert([[
            'booking_id' => 1, 'booking_number' => 'BK-1', 'client' => 1, 'mode' => 10,
            'is_draft' => 0, 'locked' => 0,
        ]]);
    }

    private function usuario(int $rol = User::ROLE_ADMIN): User
    {
        return User::forceCreate([
            'username' => 'operador'.$rol, 'password' => 'secreto-de-prueba', 'role' => $rol, 'status' => 1,
        ]);
    }

    private function detalle(int $rol = User::ROLE_ADMIN): Testable
    {
        $this->actingAs($this->usuario($rol));

        return Livewire::test(BookingDetail::class, ['booking' => 1]);
    }

    public function test_agregar_un_contenedor(): void
    {
        $this->detalle()
            ->call('addContainer')
            ->set('containerNumber', 'FFAU6079411')
            ->set('containerSeal', '78460')
            ->set('containerType', '1')
            ->set('containerQuantity', '1')
            ->set('containerCommodity', 'Aguacate')
            ->call('saveContainer')
            ->assertHasNoErrors();

        $contenedor = DB::table('containers')->first();

        $this->assertSame('FFAU6079411', $contenedor->number);
        $this->assertSame(1, (int) $contenedor->booking);
    }

    /**
     * En la tabla `containers` la mercancía y el tipo son NOT NULL sin default:
     * validarlos como opcionales terminaba en un error de base que el usuario
     * no entendía.
     */
    public function test_la_mercancia_y_el_tipo_son_obligatorios(): void
    {
        $this->detalle()
            ->call('addContainer')
            ->set('containerQuantity', '1')
            ->call('saveContainer')
            ->assertHasErrors(['containerType', 'containerCommodity']);

        $this->assertSame(0, DB::table('containers')->count());
    }

    /** Las columnas de auditoría son NOT NULL y de tipo DATE; la bitácora las lee. */
    public function test_el_alta_firma_autor_y_fecha(): void
    {
        $this->detalle()
            ->call('addContainer')
            ->set('containerType', '1')
            ->set('containerCommodity', 'Aguacate')
            ->call('saveContainer')
            ->assertHasNoErrors();

        $contenedor = DB::table('containers')->first();
        $usuario = (int) DB::table('users')->value('usr_id');

        $this->assertSame(
            [$usuario, $usuario, now()->toDateString(), now()->toDateString()],
            [(int) $contenedor->created_by, (int) $contenedor->modified_by, $contenedor->created_at, $contenedor->modified_at],
        );
    }

    public function test_editar_firma_quien_modifico(): void
    {
        DB::table('containers')->insert([
            'container_ID' => 1, 'booking' => 1, 'container_type' => 1, 'quantity' => 1, 'comodity' => 'Aguacate',
            'created_by' => 99, 'modified_by' => 99, 'created_at' => '2026-01-01', 'modified_at' => '2026-01-01',
        ]);

        $this->detalle()->call('editContainer', 1)->set('containerSeal', 'S-1')->call('saveContainer')->assertHasNoErrors();

        $contenedor = DB::table('containers')->first();

        $this->assertSame(
            [99, (int) DB::table('users')->value('usr_id'), '2026-01-01', now()->toDateString()],
            [(int) $contenedor->created_by, (int) $contenedor->modified_by, $contenedor->created_at, $contenedor->modified_at],
        );
    }

    /**
     * Antes de borrar se firma `modified_by`, como el original: la bitácora
     * `containers_history` la escribe un disparador a partir del renglón, y sin
     * la firma el renglón de baja quedaba sin autor.
     */
    public function test_quitar_firma_antes_de_borrar(): void
    {
        DB::table('containers')->insert(['container_ID' => 1, 'booking' => 1, 'container_type' => 1, 'quantity' => 1]);

        $consultas = [];
        DB::listen(function ($consulta) use (&$consultas): void {
            $verbo = strtok($consulta->sql, ' ');

            if (str_contains($consulta->sql, '"containers"') && $verbo !== 'select') {
                $consultas[] = $verbo;
            }
        });

        $this->detalle()->call('deleteContainer', 1);

        $this->assertSame(['update', 'delete'], $consultas);
    }

    public function test_la_cantidad_no_puede_ser_cero(): void
    {
        $this->detalle()
            ->call('addContainer')
            ->set('containerQuantity', '0')
            ->call('saveContainer')
            ->assertHasErrors('containerQuantity');

        $this->assertSame(0, DB::table('containers')->count());
    }

    public function test_editar_un_contenedor(): void
    {
        DB::table('containers')->insert([
            'container_ID' => 1, 'booking' => 1, 'number' => 'FFAU6079411', 'quantity' => 1, 'container_type' => 1, 'comodity' => 'Aguacate',
        ]);

        $this->detalle()
            ->call('editContainer', 1)
            ->assertSet('containerNumber', 'FFAU6079411')
            ->set('containerNumber', 'MSCU1234567')
            ->call('saveContainer')
            ->assertHasNoErrors();

        $this->assertSame('MSCU1234567', DB::table('containers')->where('container_ID', 1)->value('number'));
        $this->assertSame(1, DB::table('containers')->count());
    }

    public function test_quitar_un_contenedor(): void
    {
        DB::table('containers')->insert(['container_ID' => 1, 'booking' => 1, 'container_type' => 1, 'quantity' => 1]);

        $this->detalle()->call('deleteContainer', 1);

        $this->assertSame(0, DB::table('containers')->count());
    }

    /** Un contenedor de otro booking no existe para esta pantalla. */
    public function test_no_se_toca_el_contenedor_de_otro_booking(): void
    {
        DB::table('booking')->insert([[
            'booking_id' => 2, 'booking_number' => 'BK-2', 'client' => 1, 'mode' => 10, 'is_draft' => 0,
        ]]);
        DB::table('containers')->insert(['container_ID' => 9, 'booking' => 2, 'container_type' => 1, 'quantity' => 1]);

        $this->detalle()->call('deleteContainer', 9);

        $this->assertSame(1, DB::table('containers')->count(), 'No debe borrar el contenedor de otro booking.');
    }

    public function test_un_booking_cerrado_no_recibe_contenedores(): void
    {
        DB::table('booking')->where('booking_id', 1)->update(['locked' => 1]);

        $this->detalle()->call('addContainer')->assertForbidden();
    }

    public function test_quien_no_es_administrador_no_toca_los_contenedores(): void
    {
        DB::table('containers')->insert(['container_ID' => 1, 'booking' => 1, 'container_type' => 1, 'quantity' => 1]);

        $this->detalle(User::ROLE_USER)->call('deleteContainer', 1)->assertForbidden();

        $this->assertSame(1, DB::table('containers')->count());
    }

    // ------------------------------------------------------------ Cierre

    /** Cerrar es del super administrador, como `lock` en el original. */
    public function test_cerrar_un_booking(): void
    {
        $this->detalle(User::ROLE_SUPER_ADMIN)->call('lock');

        $this->assertSame(1, (int) DB::table('booking')->where('booking_id', 1)->value('locked'));
    }

    public function test_un_administrador_no_cierra(): void
    {
        $this->detalle()->assertDontSee('Cerrar booking')->call('lock')->assertForbidden();

        $this->assertSame(0, (int) DB::table('booking')->where('booking_id', 1)->value('locked'));
    }

    /** Reabrir permite tocar importes ya conciliados: es del super administrador. */
    public function test_solo_el_super_administrador_reabre(): void
    {
        DB::table('booking')->where('booking_id', 1)->update(['locked' => 1]);

        $this->detalle()->call('unlock')->assertForbidden();
        $this->assertSame(1, (int) DB::table('booking')->where('booking_id', 1)->value('locked'));

        $this->detalle(User::ROLE_SUPER_ADMIN)->call('unlock');
        $this->assertSame(0, (int) DB::table('booking')->where('booking_id', 1)->value('locked'));
    }

    public function test_quien_no_es_administrador_no_cierra(): void
    {
        $this->detalle(User::ROLE_USER)->call('lock')->assertForbidden();

        $this->assertSame(0, (int) DB::table('booking')->where('booking_id', 1)->value('locked'));
    }

    /** Como el `pageSummary` del grid del viejo: la suma de cantidades al pie. */
    public function test_el_pie_suma_las_cantidades(): void
    {
        DB::table('containers')->insert([
            ['container_ID' => 1, 'booking' => 1, 'container_type' => 1, 'quantity' => 2, 'comodity' => 'Aguacate'],
            ['container_ID' => 2, 'booking' => 1, 'container_type' => 1, 'quantity' => 3, 'comodity' => 'Limón'],
        ]);

        $this->detalle()->assertSeeHtml('data-total-contenedores>5</td>');
    }
}
