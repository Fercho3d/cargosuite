<?php

namespace Tests\Feature\Transactions;

use App\Livewire\Transactions\TransactionDetail;
use App\Models\Core\Charge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\LegacyDatabaseTestCase;

/**
 * Pantalla de detalle de una transacción.
 *
 * Va contra la base real porque lo que interesa comprobar es que el encabezado
 * salga del mismo motor que el listado y que los conceptos sumen el total del
 * documento — dos cosas que solo se ven con datos de verdad.
 */
#[Group('parity')]
class TransactionDetailTest extends LegacyDatabaseTestCase
{
    private function admin(): User
    {
        $usuario = User::query()
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->orderBy('usr_id')
            ->first();

        if (! $usuario) {
            $this->markTestSkipped('La base local no tiene un administrador.');
        }

        return $usuario;
    }

    /** Una transacción con conceptos, para que el desglose tenga qué enseñar. */
    private function transaccionConCargos(): int
    {
        $id = DB::table('charge')
            ->join('transaction', 'transaction.transc_id', '=', 'charge.transaction')
            ->join('booking', 'booking.booking_id', '=', 'transaction.booking')
            ->where('booking.mode', 10)
            ->value('charge.transaction');

        if (! $id) {
            $this->markTestSkipped('La base local no tiene transacciones con conceptos.');
        }

        return (int) $id;
    }

    public function test_el_detalle_es_solo_para_administradores(): void
    {
        $noAdmin = User::query()
            ->whereNotIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->orderBy('usr_id')
            ->first();

        if (! $noAdmin) {
            $this->markTestSkipped('La base local no tiene un usuario sin rol administrativo.');
        }

        $this->actingAs($noAdmin)
            ->get(route('transactions.show', $this->transaccionConCargos()))
            ->assertForbidden();
    }

    public function test_el_detalle_abre_y_enseña_el_numero_de_la_transaccion(): void
    {
        $id = $this->transaccionConCargos();
        $numero = DB::table('transaction')->where('transc_id', $id)->value('tran_number');

        $this->actingAs($this->admin())
            ->get(route('transactions.show', $id))
            ->assertOk()
            ->assertSee($numero);
    }

    public function test_una_transaccion_inexistente_responde_404(): void
    {
        $siguiente = (int) DB::table('transaction')->max('transc_id') + 1000;

        $this->actingAs($this->admin())
            ->get(route('transactions.show', $siguiente))
            ->assertNotFound();
    }

    /**
     * El encabezado se lee del motor de consulta y los conceptos de la tabla
     * `charge`. Si las dos vías dejaran de coincidir, el detalle enseñaría un
     * total distinto al del listado.
     */
    public function test_los_conceptos_suman_el_total_del_documento(): void
    {
        $this->actingAs($this->admin());

        $id = $this->transaccionConCargos();

        $datos = Livewire::test(TransactionDetail::class, ['transaction' => $id]);
        $fila = $datos->viewData('fila');
        $cargos = $datos->viewData('cargos');

        $sumaConceptos = $cargos->sum(fn (Charge $c) => $c->total);

        // `total_natural_amount` va en la moneda del documento, pero con el signo
        // del motor: las facturas suman y los costos restan. Los conceptos no
        // llevan signo, así que la comparación es en magnitud.
        $this->assertEqualsWithDelta(
            round(abs($sumaConceptos), 2),
            round(abs((float) $fila->total_natural_amount), 2),
            0.02,
            'El desglose de conceptos no cuadra con el total del documento.',
        );
    }
}
