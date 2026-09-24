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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Una nómina, en su propia página.
 *
 * Se paga entera o empleado por empleado, y cada recibo se timbra por su
 * cuenta en cuanto está pagado. A quien ya se le pagó no se le mueve nada.
 */
class PayrollDetail extends Component
{
    public int $nominaId;

    // --- Renglón suelto ---
    public string $empleado = '';

    public string $concepto = '';

    public string $tipo = 'percepcion';

    public string $importe = '';

    /** Compañía con la que se timbra una nómina que aún no tiene una. */
    public string $emisor = '';

    public function mount(int $nomina): void
    {
        abort_unless(config('marca.nomina'), 404);
        $this->assertAdmin();
        abort_unless(DB::table('nomina')->where('nomina_id', $nomina)->exists(), 404);

        $this->nominaId = $nomina;
    }

    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
    }

    private function nomina(): object
    {
        return DB::table('nomina')->where('nomina_id', $this->nominaId)->first() ?? abort(404);
    }

    /** Una nómina pagada no se toca, ni lo de un empleado ya pagado: el dinero ya salió. */
    private function assertEditable(?int $empleado = null): void
    {
        abort_if($this->nomina()->estado === 'pagada', 422, __('Esta nómina ya se pagó y no se puede modificar.'));
        abort_if($empleado !== null && Payroll::pagados($this->nominaId)->contains($empleado), 422,
            __('A este empleado ya se le pagó: su recibo no se puede modificar.'));
    }

    public function agregarRenglon(): void
    {
        $this->assertAdmin();

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

        $this->assertEditable((int) $this->empleado);

        DB::table('nomina_renglon')->insert([
            'nomina_id' => $this->nominaId,
            'empleado_id' => (int) $this->empleado,
            'concepto' => $this->concepto,
            'tipo' => $this->tipo,
            'importe' => (float) $this->importe,
        ]);
        Payroll::recalcula($this->nominaId);

        $this->reset(['concepto', 'importe']);
        $this->tipo = 'percepcion';
    }

    public function quitarRenglon(int $renglon): void
    {
        $this->assertAdmin();

        $fila = DB::table('nomina_renglon')->where('renglon_id', $renglon)->where('nomina_id', $this->nominaId)->first();
        abort_if($fila === null, 404);
        $this->assertEditable((int) $fila->empleado_id);

        DB::table('nomina_renglon')->where('renglon_id', $renglon)->delete();
        Payroll::recalcula($this->nominaId);
    }

    /** Toda la nómina de una vez: a los que faltaban se les paga hoy. */
    public function pagar(): void
    {
        $this->assertAdmin();
        $this->assertEditable();

        DB::table('nomina')->where('nomina_id', $this->nominaId)->update(['estado' => 'pagada', 'pagada_en' => now()]);
        session()->flash('status', __('Nómina marcada como pagada.'));
    }

    /**
     * Un solo empleado. Cuando ya se les pagó a todos, la nómina queda pagada
     * sola: no hay nada más que mover.
     */
    public function pagarEmpleado(int $empleado): void
    {
        $this->assertAdmin();
        $this->assertEditable($empleado);
        abort_unless(DB::table('nomina_renglon')->where('nomina_id', $this->nominaId)->where('empleado_id', $empleado)->exists(), 404);

        DB::table('nomina_recibo')->updateOrInsert(
            ['nomina_id' => $this->nominaId, 'empleado_id' => $empleado],
            ['pagado_en' => now()],
        );

        $pendientes = DB::table('nomina_renglon')->where('nomina_id', $this->nominaId)
            ->whereNotIn('empleado_id', Payroll::pagados($this->nominaId))->exists();

        if (! $pendientes) {
            DB::table('nomina')->where('nomina_id', $this->nominaId)->update(['estado' => 'pagada', 'pagada_en' => now()]);
        }

        session()->flash('status', __('Pago registrado.'));
    }

    /** Timbra toda la nómina pagada, o solo el recibo de `$empleado`. */
    public function timbrar(StampPayslip $timbrado, ?int $empleado = null): void
    {
        $this->assertAdmin();

        try {
            $cuenta = $timbrado->handle($this->nominaId, $this->emisor === '' ? null : (int) $this->emisor, $empleado);
        } catch (CfdiException $e) {
            $this->addError('timbrado', $e->getMessage());

            return;
        }

        session()->flash('status', __(':timbrados recibos timbrados, :errores con error.', $cuenta));
    }

    /**
     * Los recibos de nómina se cancelan sin que el empleado tenga que aceptar,
     * así que el acuse del PAC basta para darlo por cancelado y volver a timbrar.
     */
    public function cancelarRecibo(int $recibo, PacClient $pac): void
    {
        $this->assertAdmin();

        $fila = DB::table('nomina_recibo')->where('recibo_id', $recibo)->where('nomina_id', $this->nominaId)
            ->where('estado', 'timbrado')->first();
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

        $fila = DB::table('nomina_recibo')->where('recibo_id', $recibo)->where('nomina_id', $this->nominaId)
            ->whereNotNull('uuid')->first();
        abort_if($fila === null, 404);

        $ruta = StampPayslip::carpeta($this->nomina())."/{$fila->uuid}.{$formato}";
        abort_unless(Storage::disk('documentos')->exists($ruta), 404);

        return Storage::disk('documentos')->download($ruta);
    }

    public function reabrir(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        // Con recibos vigentes ante el SAT, cambiar los importes dejaría el CFDI
        // diciendo otra cosa que la nómina: primero se cancelan.
        abort_if(DB::table('nomina_recibo')->where('nomina_id', $this->nominaId)->where('estado', 'timbrado')->exists(), 422,
            __('Esta nómina tiene recibos timbrados: cancélalos antes de reabrirla.'));

        DB::table('nomina')->where('nomina_id', $this->nominaId)->update(['estado' => 'abierta', 'pagada_en' => null]);
        DB::table('nomina_recibo')->where('nomina_id', $this->nominaId)->update(['pagado_en' => null]);
    }

    public function borrar()
    {
        $this->assertAdmin();
        $this->assertEditable();
        abort_if(Payroll::pagados($this->nominaId)->isNotEmpty(), 422,
            __('Esta nómina ya tiene empleados pagados y no se puede borrar.'));

        // Los renglones se van con ella: si no, sus liquidaciones quedarían
        // marcadas como ya cobradas en nómina para siempre.
        DB::table('nomina_recibo')->where('nomina_id', $this->nominaId)->delete();
        DB::table('nomina_renglon')->where('nomina_id', $this->nominaId)->delete();
        DB::table('nomina')->where('nomina_id', $this->nominaId)->delete();

        session()->flash('status', __('Nómina borrada.'));

        return $this->redirectRoute('payments.payroll', navigate: true);
    }

    /**
     * Un renglón por empleado con su neto y su CLABE: lo que se sube a la
     * dispersión bancaria.
     */
    public function exportar(): StreamedResponse
    {
        $this->assertAdmin();

        $nomina = $this->nomina();
        $filas = Payroll::porEmpleado($this->nominaId);

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

    public function render()
    {
        $nomina = $this->nomina();

        return view('livewire.payments.payroll-detail', [
            'n' => $nomina,
            'detalle' => Payroll::porEmpleado($this->nominaId),
            'renglones' => DB::table('nomina_renglon')->where('nomina_id', $this->nominaId)->get()->groupBy('empleado_id'),
            'totales' => Payroll::totales($this->nominaId),
            'recibos' => DB::table('nomina_recibo')->where('nomina_id', $this->nominaId)->get()->keyBy('empleado_id'),
            'timbrable' => config('timbrado.habilitado')
                ? DB::table('empleado')->whereIn('regimen', ['sueldos', 'asimilados'])->pluck('empleado_id')->flip()
                : collect(),
            'companias' => Company::where('active', 1)->orderBy('name')->pluck('name', 'company_id')->all(),
            'empleados' => DB::table('empleado')->where('activo', 1)->orderBy('nombre')->pluck('nombre', 'empleado_id')->all(),
        ])->layout('components.app-layout', ['title' => __('Nómina').' '.$nomina->numero]);
    }
}
