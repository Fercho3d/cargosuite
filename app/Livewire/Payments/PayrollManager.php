<?php

namespace App\Livewire\Payments;

use App\Actions\Payroll\StampPayslip;
use App\Models\Core\Company;
use App\Support\Cfdi\CfdiException;
use App\Support\Cfdi\PacClient;
use App\Support\Payroll\Payroll;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Nómina interna.
 *
 * Reúne lo que se paga —sueldos, viajes, bonos, descuentos—, calcula impuestos
 * y cuotas según el régimen de cada empleado y saca el neto. Ya pagada, timbra
 * el CFDI de nómina de cada empleado de sueldos o asimilados (`StampPayslip`).
 */
class PayrollManager extends Component
{
    use WithPagination;

    public ?int $abierta = null;

    public bool $creando = false;

    public string $desde = '';

    public string $hasta = '';

    public string $periodicidad = 'quincenal';

    // --- Renglón suelto ---
    public string $empleado = '';

    public string $concepto = '';

    public string $tipo = 'percepcion';

    public string $importe = '';

    /** Compañía con la que se timbra una nómina que aún no tiene una. */
    public string $emisor = '';

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

    /** Una nómina pagada no se toca: el dinero ya salió. */
    private function assertAbierta(int $id): object
    {
        $fila = DB::table('nomina')->where('nomina_id', $id)->first();

        abort_if($fila === null, 404);
        abort_if($fila->estado === 'pagada', 422, __('Esta nómina ya se pagó y no se puede modificar.'));

        return $fila;
    }

    public function crear(): void
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

        $this->creando = false;
        $this->abierta = $id;
        session()->flash('status', __('Nómina creada con los sueldos y los viajes del periodo.'));
    }

    public function agregarRenglon(): void
    {
        $this->assertAdmin();
        abort_if($this->abierta === null, 404);
        $this->assertAbierta($this->abierta);

        $this->validate([
            'empleado' => ['required', 'exists:empleado,empleado_id'],
            'concepto' => ['required', 'string', 'max:120'],
            'tipo' => ['required', 'in:percepcion,deduccion'],
            'importe' => ['required', 'numeric', 'min:0'],
        ], attributes: [
            'empleado' => mb_strtolower(__('Empleado')),
            'concepto' => mb_strtolower(__('Concepto')),
            'importe' => mb_strtolower(__('Importe')),
        ]);

        DB::table('nomina_renglon')->insert([
            'nomina_id' => $this->abierta,
            'empleado_id' => (int) $this->empleado,
            'concepto' => $this->concepto,
            'tipo' => $this->tipo,
            'importe' => (float) $this->importe,
        ]);
        Payroll::recalcula($this->abierta);

        $this->reset(['concepto', 'importe']);
        $this->tipo = 'percepcion';
    }

    public function quitarRenglon(int $renglon): void
    {
        $this->assertAdmin();

        $fila = DB::table('nomina_renglon')->where('renglon_id', $renglon)->first();
        abort_if($fila === null, 404);
        $this->assertAbierta((int) $fila->nomina_id);

        DB::table('nomina_renglon')->where('renglon_id', $renglon)->delete();
        Payroll::recalcula((int) $fila->nomina_id);
    }

    public function pagar(int $id): void
    {
        $this->assertAdmin();
        $this->assertAbierta($id);

        DB::table('nomina')->where('nomina_id', $id)->update(['estado' => 'pagada', 'pagada_en' => now()]);
        session()->flash('status', __('Nómina marcada como pagada.'));
    }

    public function timbrar(int $id, StampPayslip $timbrado): void
    {
        $this->assertAdmin();

        try {
            $cuenta = $timbrado->handle($id, $this->emisor === '' ? null : (int) $this->emisor);
        } catch (CfdiException $e) {
            $this->addError('timbrado', $e->getMessage());

            return;
        }

        $this->abierta = $id;
        session()->flash('status', __(':timbrados recibos timbrados, :errores con error.', $cuenta));
    }

    /**
     * Los recibos de nómina se cancelan sin que el empleado tenga que aceptar,
     * así que el acuse del PAC basta para darlo por cancelado y volver a timbrar.
     */
    public function cancelarRecibo(int $recibo, PacClient $pac): void
    {
        $this->assertAdmin();

        $fila = DB::table('nomina_recibo')->where('recibo_id', $recibo)->where('estado', 'timbrado')->first();
        abort_if($fila === null, 404);

        try {
            $acuse = $pac->cancel($fila->uuid, $fila->rfc_emisor, '02');
        } catch (CfdiException $e) {
            $this->addError('timbrado', $e->getMessage());

            return;
        }

        DB::table('nomina_recibo')->where('recibo_id', $recibo)->update([
            'estado' => 'cancelado', 'mensaje' => mb_substr((string) $acuse->mensaje, 0, 500), 'cancelado_en' => now(),
        ]);
        session()->flash('status', __('Recibo cancelado ante el SAT. Ya se puede volver a timbrar.'));
    }

    public function descargarRecibo(int $recibo, string $formato)
    {
        $this->assertAdmin();
        abort_unless(in_array($formato, ['xml', 'pdf'], true), 404);

        $fila = DB::table('nomina_recibo as r')->join('nomina as n', 'n.nomina_id', '=', 'r.nomina_id')
            ->where('r.recibo_id', $recibo)->whereNotNull('r.uuid')->first(['r.uuid', 'n.numero']);
        abort_if($fila === null, 404);

        $ruta = StampPayslip::carpeta($fila)."/{$fila->uuid}.{$formato}";
        abort_unless(Storage::disk('documentos')->exists($ruta), 404);

        return Storage::disk('documentos')->download($ruta);
    }

    public function reabrir(int $id): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        // Con recibos vigentes ante el SAT, cambiar los importes dejaría el CFDI
        // diciendo otra cosa que la nómina: primero se cancelan.
        abort_if(DB::table('nomina_recibo')->where('nomina_id', $id)->where('estado', 'timbrado')->exists(), 422,
            __('Esta nómina tiene recibos timbrados: cancélalos antes de reabrirla.'));

        DB::table('nomina')->where('nomina_id', $id)->update(['estado' => 'abierta', 'pagada_en' => null]);
    }

    public function borrar(int $id): void
    {
        $this->assertAdmin();
        $this->assertAbierta($id);

        // Los renglones se van con ella: si no, sus liquidaciones quedarían
        // marcadas como ya cobradas en nómina para siempre.
        DB::table('nomina_renglon')->where('nomina_id', $id)->delete();
        DB::table('nomina')->where('nomina_id', $id)->delete();

        if ($this->abierta === $id) {
            $this->abierta = null;
        }

        session()->flash('status', __('Nómina borrada.'));
    }

    /**
     * Un renglón por empleado con su neto y su CLABE: lo que se sube a la
     * dispersión bancaria.
     */
    public function exportar(int $id): StreamedResponse
    {
        $this->assertAdmin();

        $nomina = DB::table('nomina')->where('nomina_id', $id)->first();
        abort_if($nomina === null, 404);

        $filas = Payroll::porEmpleado($id);

        return response()->streamDownload(function () use ($filas) {
            $salida = fopen('php://output', 'wb');

            // BOM: sin él, Excel se come los acentos del nombre del empleado.
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, ['Numero', 'Empleado', 'Puesto', 'CLABE', 'Percepciones', 'Deducciones', 'Neto', 'Costo patronal']);

            foreach ($filas as $f) {
                fputcsv($salida, [$f->numero, $f->nombre, $f->puesto, $f->clabe,
                    // En crudo, sin separador de miles: con formato, Excel los
                    // toma como texto y no se pueden sumar.
                    number_format($f->percepciones, 2, '.', ''),
                    number_format($f->deducciones, 2, '.', ''),
                    number_format($f->neto, 2, '.', ''),
                    number_format($f->patronal, 2, '.', '')]);
            }

            fclose($salida);
        }, 'nomina-'.$nomina->numero.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function ver(?int $id): void
    {
        $this->abierta = $this->abierta === $id ? null : $id;
    }

    public function render()
    {
        return view('livewire.payments.payroll-manager', [
            'nominas' => DB::table('nomina')->orderByDesc('nomina_id')->paginate(12),
            'detalle' => $this->abierta === null ? collect() : Payroll::porEmpleado($this->abierta),
            'renglones' => $this->abierta === null
                ? collect()
                : DB::table('nomina_renglon')->where('nomina_id', $this->abierta)->get()->groupBy('empleado_id'),
            'totales' => $this->abierta === null ? null : Payroll::totales($this->abierta),
            'recibos' => $this->abierta === null
                ? collect()
                : DB::table('nomina_recibo')->where('nomina_id', $this->abierta)->get()->keyBy('empleado_id'),
            'timbrable' => config('timbrado.habilitado')
                ? DB::table('empleado')->whereIn('regimen', ['sueldos', 'asimilados'])->pluck('empleado_id')->flip()
                : collect(),
            'companias' => Company::where('active', 1)->orderBy('name')->pluck('name', 'company_id')->all(),
            'empleados' => DB::table('empleado')->where('activo', 1)->orderBy('nombre')->pluck('nombre', 'empleado_id')->all(),
        ])->layout('components.app-layout', ['title' => __('Nómina')]);
    }
}
