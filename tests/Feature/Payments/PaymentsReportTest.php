<?php

namespace Tests\Feature\Payments;

use App\Livewire\Payments\PaymentRequestList;
use App\Livewire\Payments\PaymentsReport;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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
    protected function setUp(): void
    {
        parent::setUp();

        // La lista de solicitudes revalúa a la fecha de hoy y pide ese tipo de
        // cambio a Banxico si falta; la base real es solo lectura, así que la
        // respuesta se finge vacía y no se registra nada.
        Http::preventStrayRequests();
        Http::fake(['www.banxico.org.mx/*' => Http::response(['bmx' => ['series' => [['datos' => []]]]])]);
    }

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
        // Interno y activo: a uno dado de baja `EnsureUserIsActive` lo manda al
        // login (302) antes de que la puerta de administradores conteste 403.
        $noAdmin = User::query()
            ->whereNotIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 1))
            ->where(fn ($q) => $q->whereNull('access')->orWhere('access', User::ACCESS_INTERNAL))
            ->orderBy('usr_id')
            ->first();

        if (! $noAdmin) {
            $this->markTestSkipped('La base local no tiene un usuario interno activo sin rol administrativo.');
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
     * Un renglón abre la lista de solicitudes ya filtrada, y esa lista tiene que
     * sumar lo que dice el renglón. Es la comprobación que en el original no se
     * cumplía: dos de los tres detalles no heredaban el filtro de «pagadas» y
     * traían de más.
     */
    #[DataProvider('modos')]
    public function test_la_lista_que_abre_un_renglon_suma_lo_mismo(string $modo): void
    {
        $this->actingAs($this->admin());

        $reporte = Livewire::test(PaymentsReport::class, ['mode' => $modo]);

        $renglon = $reporte->viewData('filas')
            ->sortByDesc(fn ($f) => abs((float) $f->total_paid))
            ->first();

        $this->assertNotNull($renglon, 'No hay renglones que abrir.');

        parse_str((string) parse_url($reporte->instance()->requestsUrl($renglon), PHP_URL_QUERY), $parametros);

        $totales = Livewire::withQueryParams($parametros)
            ->test(PaymentRequestList::class)
            ->set('showTotals', true)
            ->viewData('totals');

        // El renglón viene con el signo del tipo y la lista puede traerlo
        // distinto, así que la comparación es en magnitud.
        $this->assertEqualsWithDelta(
            round(abs((float) $renglon->total_paid), 2),
            round(abs($totales['total_paid']), 2),
            0.05,
            'La lista no suma lo que dice el renglón.',
        );
    }

    /** @return array<string, array{string}> */
    public static function modos(): array
    {
        return ['general' => ['general'], 'por cliente' => ['customer'], 'por proveedor' => ['vendor']];
    }

    public function test_la_lista_regresa_al_reporte_con_su_filtro(): void
    {
        $this->actingAs($this->admin());

        $reporte = Livewire::test(PaymentsReport::class, ['mode' => 'general'])->set('bankId', '6');
        $url = $reporte->instance()->requestsUrl($reporte->viewData('filas')->first() ?? (object) ['type' => 1]);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $parametros);

        $this->assertSame('/pagos/reporte/general?banco=6', $parametros['volver']);
    }
}
