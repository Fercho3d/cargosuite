<?php

namespace App\Livewire\Payments;

use App\Support\Payroll\Payroll;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Nómina interna: la lista de nóminas y el alta. Cada una se abre en su propia
 * página (`PayrollDetail`), donde se paga y se timbra.
 *
 * Reúne lo que se paga —sueldos, viajes, bonos, descuentos—, calcula impuestos
 * y cuotas según el régimen de cada empleado y saca el neto. Ya pagada, timbra
 * el CFDI de nómina de cada empleado de sueldos o asimilados (`StampPayslip`).
 */
class PayrollManager extends Component
{
    use WithPagination;

    public bool $creando = false;

    public string $desde = '';

    public string $hasta = '';

    public string $periodicidad = 'quincenal';

    public function mount(): void
    {
        // La nómina se apaga entera en quien ya la lleva en otro sistema.
        abort_unless(config('marca.nomina'), 404);
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $this->desde = now()->startOfMonth()->toDateString();
        $this->hasta = now()->startOfMonth()->addDays(14)->toDateString();
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
    }

    public function crear()
    {
        $this->assertAdmin();

        $this->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'periodicidad' => ['required', 'in:semanal,catorcenal,quincenal,mensual'],
        ], attributes: ['desde' => mb_strtolower(__('Desde')), 'hasta' => mb_strtolower(__('Hasta'))]);

        $id = DB::table('nomina')->insertGetId([
            'numero' => Payroll::siguienteNumero(),
            'desde' => $this->desde,
            'hasta' => $this->hasta,
            'periodicidad' => $this->periodicidad,
            'estado' => 'abierta',
            'created_by' => auth()->id(),
            'created_at' => now(),
        ], 'nomina_id');

        // La propuesta: sueldo por los días del periodo y, para quien sea
        // operador, sus liquidaciones de viaje todavía no cobradas en nómina.
        foreach (Payroll::empleadosActivos($this->periodicidad) as $empleado) {
            foreach (Payroll::propuesta($empleado, $this->desde, $this->hasta) as $renglon) {
                DB::table('nomina_renglon')->insert($renglon + [
                    'nomina_id' => $id,
                    'empleado_id' => $empleado->empleado_id,
                ]);
            }
        }

        Payroll::recalcula($id);

        session()->flash('status', __('Nómina creada con los sueldos y los viajes del periodo.'));

        return $this->redirectRoute('payments.payroll.show', ['nomina' => $id], navigate: true);
    }

    public function render()
    {
        $nominas = DB::table('nomina')->orderByDesc('nomina_id')->paginate(12);
        $ids = $nominas->pluck('nomina_id');

        return view('livewire.payments.payroll-manager', [
            'nominas' => $nominas,
            // Cuántos van pagados, para ver de un vistazo las que se pagan a pedazos.
            'pagados' => DB::table('nomina_recibo')->whereIn('nomina_id', $ids)->whereNotNull('pagado_en')
                ->groupBy('nomina_id')->selectRaw('nomina_id, COUNT(*) as n')->pluck('n', 'nomina_id'),
            'empleadosPorNomina' => DB::table('nomina_renglon')->whereIn('nomina_id', $ids)
                ->groupBy('nomina_id')->selectRaw('nomina_id, COUNT(DISTINCT empleado_id) as n')->pluck('n', 'nomina_id'),
        ])->layout('components.app-layout', ['title' => __('Nómina')]);
    }
}
