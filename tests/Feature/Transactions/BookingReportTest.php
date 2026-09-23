<?php

namespace Tests\Feature\Transactions;

use App\Livewire\Transactions\BookingReport;
use App\Models\User;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\LegacyDatabaseTestCase;

/**
 * Reporte por booking.
 *
 * Va contra la base real porque lo que se comprueba es que agrupar por booking
 * dé exactamente los mismos importes que sumar transacción por transacción —
 * algo que solo se ve con volumen.
 */
#[Group('parity')]
class BookingReportTest extends LegacyDatabaseTestCase
{
    private const RANGO = '01/12/2022 - 31/12/2022';

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

    public function test_el_reporte_es_solo_para_administradores(): void
    {
        // Interno y activo: a los dados de baja y a los del portal los saca
        // una redirección, no un 403.
        $noAdmin = User::query()
            ->whereNotIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 1))
            ->where(fn ($q) => $q->whereNull('access')->orWhere('access', User::ACCESS_INTERNAL))
            ->orderBy('usr_id')
            ->first();

        if (! $noAdmin) {
            $this->markTestSkipped('La base local no tiene un usuario interno activo sin rol administrativo.');
        }

        $this->actingAs($noAdmin)->get(route('transactions.report.booking'))->assertForbidden();
    }

    public function test_el_reporte_abre(): void
    {
        $this->actingAs($this->admin())
            ->get(route('transactions.report.booking'))
            ->assertOk()
            ->assertSee(__('Reporte por booking'));
    }

    public function test_ver_todos_los_anios_y_limpiar_dejan_el_reporte_sin_rango(): void
    {
        $this->actingAs($this->admin());

        $reporte = Livewire::test(BookingReport::class);

        $this->assertNotSame('', $reporte->get('dates'), 'El reporte arranca acotado al año en curso.');

        $reporte->call('showAllYears')->assertSet('dates', '')->assertSee(__('todos los años'));
        $reporte->set('dates', self::RANGO)->call('clearFilters')->assertSet('dates', '');
    }

    public function test_hay_una_sola_fila_por_booking(): void
    {
        $this->actingAs($this->admin());

        $filas = Livewire::test(BookingReport::class)
            ->set('dates', self::RANGO)
            ->viewData('rows');

        $bookings = collect($filas->items())->pluck('booking_id');

        $this->assertGreaterThan(0, $bookings->count(), 'El rango de prueba se quedó sin datos.');
        $this->assertSame($bookings->count(), $bookings->unique()->count(), 'Un booking salió repetido.');
    }

    /**
     * Agrupar por booking no puede mover el dinero: la suma de ingresos y
     * egresos tiene que ser la misma que agrupando por transacción.
     */
    public function test_agrupar_por_booking_no_cambia_los_importes(): void
    {
        $porBooking = $this->totalesCon('booking');
        $porTransaccion = $this->totalesCon('transc_id');

        foreach (['income', 'expense'] as $columna) {
            $this->assertEqualsWithDelta(
                round($porTransaccion[$columna], 2),
                round($porBooking[$columna], 2),
                0.02,
                "Al agrupar por booking cambió {$columna}.",
            );
        }
    }

    /** @return array<string, float> */
    private function totalesCon(string $agrupacion): array
    {
        $filtros = TransactionFilters::make(['dates_booking' => self::RANGO]);
        $filtros->groupBy = $agrupacion;

        return TransactionQuery::make($filtros)->totals(['income', 'expense']);
    }
}
