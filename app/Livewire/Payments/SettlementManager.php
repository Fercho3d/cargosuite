<?php

namespace App\Livewire\Payments;

use App\Support\Settlements\DriverSettlement;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Liquidaciones de operadores: lo que se le paga a cada quien por sus viajes.
 *
 * Sigue la misma idea que la generación de facturación: **propone antes de
 * escribir**. Al abrir una liquidación trae los viajes del operador en el
 * periodo que todavía no están en otra, con el importe que le toca según su
 * tarifa, y quien liquida revisa y ajusta antes de guardar.
 */
class SettlementManager extends Component
{
    use WithPagination;

    #[Url(as: 'op', except: '')]
    public string $operadorFiltro = '';

    #[Url(as: 'estado', except: '')]
    public string $estadoFiltro = '';

    /** Liquidación abierta en el detalle. */
    public ?int $abierta = null;

    // --- Alta ---
    public bool $creando = false;

    public string $nuevoOperador = '';

    public string $desde = '';

    public string $hasta = '';

    // --- Renglón suelto ---
    public string $concepto = '';

    public string $tipo = 'percepcion';

    public string $importe = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $this->desde = now()->startOfMonth()->toDateString();
        $this->hasta = now()->toDateString();
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
    }

    /** Una liquidación pagada ya no se toca: el dinero salió. */
    private function assertAbierta(int $id): object
    {
        $fila = DB::table('liquidacion')->where('liquidacion_id', $id)->first();

        abort_if($fila === null, 404);
        abort_if($fila->estado === 'pagada', 422, __('Esta liquidación ya se pagó y no se puede modificar.'));

        return $fila;
    }

    public function crear(): void
    {
        $this->assertAdmin();

        $this->validate([
            'nuevoOperador' => ['required', 'exists:operador,operador_id'],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ], attributes: [
            'nuevoOperador' => mb_strtolower(__('Operador')),
            'desde' => mb_strtolower(__('Desde')),
            'hasta' => mb_strtolower(__('Hasta')),
        ]);

        $operador = DB::table('operador')->where('operador_id', (int) $this->nuevoOperador)->first();

        $id = DB::table('liquidacion')->insertGetId([
            'numero' => DriverSettlement::siguienteNumero(),
            'operador_id' => $operador->operador_id,
            'desde' => $this->desde,
            'hasta' => $this->hasta,
            'estado' => 'abierta',
            'created_by' => auth()->id(),
            'created_at' => now(),
        ], 'liquidacion_id');

        // La propuesta: un renglón por viaje, con lo que le toca según su tarifa.
        foreach (DriverSettlement::viajesPendientes($operador->operador_id, $this->desde, $this->hasta) as $viaje) {
            DB::table('liquidacion_renglon')->insert([
                'liquidacion_id' => $id,
                'booking' => $viaje->booking_id,
                'concepto' => trim((string) $viaje->booking_number).' · '.trim((string) $viaje->origen).' → '.trim((string) $viaje->destino),
                'tipo' => 'percepcion',
                'importe' => DriverSettlement::propuestaPorViaje($operador, (int) $viaje->booking_id),
                'fecha' => $viaje->loading_EDT,
            ]);
        }

        $this->creando = false;
        $this->abierta = $id;
        $this->nuevoOperador = '';
        session()->flash('status', __('Liquidación creada con los viajes del periodo.'));
    }

    public function ajustar(int $renglon, string $importe): void
    {
        $this->assertAdmin();

        $fila = DB::table('liquidacion_renglon')->where('renglon_id', $renglon)->first();
        abort_if($fila === null, 404);
        $this->assertAbierta((int) $fila->liquidacion_id);

        DB::table('liquidacion_renglon')->where('renglon_id', $renglon)
            ->update(['importe' => max(0, (float) $importe)]);
    }

    public function agregarRenglon(): void
    {
        $this->assertAdmin();
        abort_if($this->abierta === null, 404);
        $this->assertAbierta($this->abierta);

        $this->validate([
            'concepto' => ['required', 'string', 'max:120'],
            'tipo' => ['required', 'in:percepcion,deduccion'],
            'importe' => ['required', 'numeric', 'min:0'],
        ], attributes: [
            'concepto' => mb_strtolower(__('Concepto')),
            'importe' => mb_strtolower(__('Importe')),
        ]);

        DB::table('liquidacion_renglon')->insert([
            'liquidacion_id' => $this->abierta,
            'concepto' => $this->concepto,
            'tipo' => $this->tipo,
            'importe' => (float) $this->importe,
            'fecha' => now()->toDateString(),
        ]);

        $this->reset(['concepto', 'importe']);
        $this->tipo = 'percepcion';
    }

    public function quitarRenglon(int $renglon): void
    {
        $this->assertAdmin();

        $fila = DB::table('liquidacion_renglon')->where('renglon_id', $renglon)->first();
        abort_if($fila === null, 404);
        $this->assertAbierta((int) $fila->liquidacion_id);

        DB::table('liquidacion_renglon')->where('renglon_id', $renglon)->delete();
    }

    public function pagar(int $id): void
    {
        $this->assertAdmin();
        $this->assertAbierta($id);

        DB::table('liquidacion')->where('liquidacion_id', $id)
            ->update(['estado' => 'pagada', 'pagada_en' => now()]);

        session()->flash('status', __('Liquidación marcada como pagada.'));
    }

    /** Reabrir es del super administrador: deshace un pago ya registrado. */
    public function reabrir(int $id): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        DB::table('liquidacion')->where('liquidacion_id', $id)
            ->update(['estado' => 'abierta', 'pagada_en' => null]);
    }

    public function borrar(int $id): void
    {
        $this->assertAdmin();
        $this->assertAbierta($id);

        // Los renglones se van con ella: dejarlos sueltos haría que sus viajes
        // parecieran ya liquidados para siempre.
        DB::table('liquidacion_renglon')->where('liquidacion_id', $id)->delete();
        DB::table('liquidacion')->where('liquidacion_id', $id)->delete();

        if ($this->abierta === $id) {
            $this->abierta = null;
        }

        session()->flash('status', __('Liquidación borrada.'));
    }

    public function ver(?int $id): void
    {
        $this->abierta = $this->abierta === $id ? null : $id;
    }

    public function render()
    {
        $liquidaciones = DB::table('liquidacion as l')
            ->leftJoin('operador as o', 'o.operador_id', '=', 'l.operador_id')
            ->when($this->operadorFiltro, fn ($q, $v) => $q->where('l.operador_id', $v))
            ->when($this->estadoFiltro, fn ($q, $v) => $q->where('l.estado', $v))
            ->orderByDesc('l.liquidacion_id')
            ->paginate(15, ['l.*', 'o.nombre as operador']);

        $renglones = $this->abierta === null
            ? collect()
            : DB::table('liquidacion_renglon')->where('liquidacion_id', $this->abierta)
                ->orderBy('tipo')->orderBy('renglon_id')->get();

        return view('livewire.payments.settlement-manager', [
            'liquidaciones' => $liquidaciones,
            'renglones' => $renglones,
            'totales' => $this->abierta === null ? null : DriverSettlement::totales($this->abierta),
            'operadores' => DB::table('operador')->where('activo', 1)->orderBy('nombre')->pluck('nombre', 'operador_id')->all(),
        ])->layout('components.app-layout', ['title' => __('Liquidaciones')]);
    }
}
