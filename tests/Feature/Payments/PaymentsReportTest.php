<?php

namespace Tests\Feature\Payments;

use App\Livewire\Payments\PaymentsReport;
use App\Models\User;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\LegacyDatabaseTestCase;

/**
 * Las tres pantallas de reportes de cobros y pagos.
 *
 * Van contra la base real: lo que interesa comprobar es que cada modo agrupe por
 * lo que debe y que el desglose de un renglón sume ese mismo renglón, y eso solo
 * se ve con datos de verdad.
 */
#[Group('parity')]
class PaymentsReportTest extends LegacyDatabaseTestCase
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

    public function test_los_reportes_son_solo_para_administradores(): void
    {
        $noAdmin = User::query()
            ->whereNotIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->orderBy('usr_id')
            ->first();

        if (! $noAdmin) {
            $this->markTestSkipped('La base local no tiene un usuario sin rol administrativo.');
        }

        $this->actingAs($noAdmin);

        foreach (['customer', 'vendor', 'general'] as $modo) {
            $this->get(route("payments.report.{$modo}"))->assertForbidden();
        }
    }

    public function test_cada_ruta_abre_su_pantalla(): void
    {
        $this->actingAs($this->admin());

        // El modo viaja como valor por omisión de la ruta hasta el `mount()`;
        // si eso se rompiera, las tres direcciones mostrarían lo mismo.
        $this->get(route('payments.report.customer'))->assertOk()->assertSee('<title>'.__('Cobros por cliente'), false);
        $this->get(route('payments.report.vendor'))->assertOk()->assertSee('<title>'.__('Pagos por proveedor'), false);
        $this->get(route('payments.report.general'))->assertOk()->assertSee('<title>'.__('Cobros y pagos'), false);
    }

    public function test_el_reporte_general_separa_cobros_de_pagos(): void
    {
        $this->actingAs($this->admin());

        $filas = Livewire::test(PaymentsReport::class, ['mode' => 'general'])->viewData('filas');

        $tipos = $filas->pluck('type')->map(fn ($t) => (int) $t)->sort()->values()->all();

        $this->assertSame([1, 2], $tipos, 'El reporte general debe traer un renglón por tipo.');
    }

    public function test_el_reporte_por_cliente_solo_trae_cobros(): void
    {
        $this->actingAs($this->admin());

        $filas = Livewire::test(PaymentsReport::class, ['mode' => 'customer'])->viewData('filas');

        $this->assertGreaterThan(0, $filas->count());

        foreach ($filas as $fila) {
            $this->assertNotNull($fila->client_id, 'Un renglón del reporte por cliente salió sin cliente.');
        }
    }

    /**
     * El desglose de un renglón tiene que sumar ese renglón. Es la comprobación
     * que en el original no se cumplía: dos de los tres detalles no heredaban el
     * filtro de «pagadas» y traían de más.
     */
    public function test_el_desglose_suma_el_renglon_que_abre(): void
    {
        $this->actingAs($this->admin());

        $componente = Livewire::test(PaymentsReport::class, ['mode' => 'customer']);

        $renglon = $componente->viewData('filas')
            ->sortByDesc(fn ($f) => abs((float) $f->total_paid))
            ->first();

        $this->assertNotNull($renglon, 'No hay renglones que desglosar.');

        $detalle = $componente->call('toggle', (string) $renglon->client_id)->viewData('detalle');

        $this->assertGreaterThan(0, $detalle->count(), 'El renglón se abrió sin solicitudes.');

        // El renglón viene con el signo del tipo y el desglose sin invertir, así
        // que la comparación es en magnitud.
        $this->assertEqualsWithDelta(
            round(abs((float) $renglon->total_paid), 2),
            round(abs($detalle->sum(fn ($d) => (float) $d->total_paid)), 2),
            0.05,
            'El desglose no suma lo que dice el renglón.',
        );
    }
}
