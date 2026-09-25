<?php

namespace App\Livewire\Rutas;

use App\Models\Core\Client;
use App\Models\Core\Provider;
use App\Support\Rutas\Tarifario;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * La ficha de una ruta: sus datos, lo que se cobra, lo que cuesta y el margen.
 *
 * Un precio no se edita: se captura uno nuevo con su «vigente desde» y el de
 * antes queda en el historial.
 */
class RouteDetail extends Component
{
    public int $rutaId;

    public string $km = '';

    public string $horas = '';

    public string $rendimiento = '';

    public bool $activo = true;

    // --- Precio nuevo ---
    public string $tipo = 'venta';

    public string $concepto = '';

    public string $cliente = '';

    public string $proveedor = '';

    public string $precio = '';

    public string $tipoCargo = '';

    public string $vigenteDesde = '';

    /** Sección donde se está agregando un precio (venta, costo, subcontrato). */
    public ?string $agregando = null;

    /** Precio vigente al que se le está capturando uno nuevo. */
    public ?int $cambiando = null;

    /** Fecha con la que se calcula la ficha: hoy, o una pasada para ver cómo estaba. */
    public string $fecha = '';

    public function mount(int $ruta): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $fila = DB::table('ruta')->where('ruta_id', $ruta)->first() ?? abort(404);

        $this->rutaId = $ruta;
        $this->km = (string) (float) $fila->km;
        $this->horas = $fila->horas === null ? '' : (string) (float) $fila->horas;
        $this->rendimiento = (string) (float) $fila->rendimiento;
        $this->activo = (bool) $fila->activo;
        $this->vigenteDesde = now()->toDateString();
        $this->fecha = now()->toDateString();
    }

    public function guardarRuta(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $this->validate([
            'km' => ['required', 'numeric', 'min:1'],
            'horas' => ['nullable', 'numeric', 'min:0'],
            'rendimiento' => ['required', 'numeric', 'min:0.5'],
        ], attributes: ['rendimiento' => mb_strtolower(__('Rendimiento'))]);

        DB::table('ruta')->where('ruta_id', $this->rutaId)->update([
            'km' => (float) $this->km,
            'horas' => $this->horas === '' ? null : (float) $this->horas,
            'rendimiento' => (float) $this->rendimiento,
            'activo' => $this->activo,
        ]);

        session()->flash('status', __('Ruta guardada.'));
    }

    public function agregarPrecio(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $this->validate([
            'tipo' => ['required', 'in:venta,costo,subcontrato'],
            'concepto' => ['required', 'string', 'max:80'],
            'cliente' => ['nullable', 'integer', 'exists:client,client_id'],
            'proveedor' => [$this->tipo === 'subcontrato' ? 'required' : 'nullable', 'integer', 'exists:provider,provider_id'],
            'precio' => ['required', 'numeric', 'min:0'],
            // Lo que se factura o se paga necesita su tipo de cargo (IVA, retención, clave SAT).
            'tipoCargo' => [$this->tipo === 'costo' ? 'nullable' : 'required', 'integer', 'exists:charge_type,charge_type_id'],
            'vigenteDesde' => ['required', 'date'],
        ], attributes: [
            'tipoCargo' => mb_strtolower(__('Tipo de cargo')),
            'concepto' => mb_strtolower(__('Concepto')), 'proveedor' => mb_strtolower(__('Proveedor')),
            'precio' => mb_strtolower(__('Precio')), 'vigenteDesde' => mb_strtolower(__('Vigente desde')),
        ]);

        DB::table('tarifa_ruta')->insert([
            'ruta_id' => $this->rutaId,
            'tipo' => $this->tipo,
            'concepto' => trim($this->concepto),
            // El cliente solo aplica a la venta; el proveedor, a lo que se paga.
            'client_id' => $this->tipo === 'venta' && $this->cliente !== '' ? (int) $this->cliente : null,
            'provider_id' => $this->tipo !== 'venta' && $this->proveedor !== '' ? (int) $this->proveedor : null,
            'precio' => (float) $this->precio,
            'charge_type_id' => $this->tipoCargo === '' ? null : (int) $this->tipoCargo,
            'vigente_desde' => $this->vigenteDesde,
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);

        $this->reset(['concepto', 'cliente', 'proveedor', 'precio', 'tipoCargo', 'agregando', 'cambiando']);
        session()->flash('status', __('Precio agregado. El anterior queda en el historial.'));
    }

    /** Solo se borra un precio capturado por error; cambiarlo es capturar otro. */
    public function borrarPrecio(int $tarifa): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        DB::table('tarifa_ruta')->where('tarifa_id', $tarifa)->where('ruta_id', $this->rutaId)->delete();
    }

    /** Abre la captura de un precio nuevo dentro de su sección. */
    public function abrirAlta(string $tipo): void
    {
        abort_unless(in_array($tipo, ['venta', 'costo', 'subcontrato'], true), 404);

        $this->reset(['concepto', 'cliente', 'proveedor', 'precio', 'tipoCargo', 'cambiando']);
        $this->resetErrorBag();
        $this->tipo = $tipo;
        $this->agregando = $tipo;
        $this->vigenteDesde = now()->toDateString();
    }

    public function cancelar(): void
    {
        $this->reset(['agregando', 'cambiando']);
        $this->resetErrorBag();
    }

    /** Toma un precio vigente para capturarle uno nuevo sin reescribir sus datos. */
    public function cambiarPrecio(int $tarifa): void
    {
        $fila = DB::table('tarifa_ruta')->where('tarifa_id', $tarifa)->where('ruta_id', $this->rutaId)->first() ?? abort(404);

        $this->tipo = $fila->tipo;
        $this->concepto = $fila->concepto;
        $this->cliente = (string) ($fila->client_id ?? '');
        $this->proveedor = (string) ($fila->provider_id ?? '');
        $this->tipoCargo = (string) ($fila->charge_type_id ?? '');
        $this->precio = '';
        $this->vigenteDesde = now()->toDateString();
        $this->agregando = null;
        $this->cambiando = $tarifa;
        $this->resetErrorBag();
    }

    public function render()
    {
        $ruta = DB::table('ruta as r')
            ->join('loading_ports as o', 'o.port_id', '=', 'r.origen_id')
            ->join('dicharge_port as d', 'd.dicharge_port_id', '=', 'r.destino_id')
            ->where('r.ruta_id', $this->rutaId)
            ->first(['r.*', 'o.port_name as origen', 'd.name as destino']);

        $fecha = $this->fecha ?: now()->toDateString();
        $vigentes = Tarifario::vigentes($this->rutaId, $fecha)->pluck('tarifa_id');
        $clientes = Client::options();
        $proveedores = Provider::options();

        $tarifas = DB::table('tarifa_ruta')->where('ruta_id', $this->rutaId)
            ->orderBy('concepto')->orderByDesc('vigente_desde')->orderByDesc('tarifa_id')->get()
            ->map(function (object $t) use ($vigentes, $clientes, $proveedores, $fecha) {
                $t->vigente = $vigentes->contains($t->tarifa_id);
                $t->futura = $t->vigente_desde > $fecha;
                $t->quien = $t->client_id ? ($clientes[$t->client_id] ?? '—') : ($t->provider_id ? ($proveedores[$t->provider_id] ?? '—') : null);

                return $t;
            })
            ->groupBy('tipo');

        $diesel = Tarifario::diesel($fecha);

        return view('livewire.rutas.route-detail', [
            'ruta' => $ruta,
            'tarifas' => $tarifas,
            'resumen' => Tarifario::resumen($ruta, $fecha),
            'diesel' => $diesel,
            'litros' => (float) $ruta->rendimiento > 0 ? round((float) $ruta->km / (float) $ruta->rendimiento, 1) : null,
            'clientes' => $clientes,
            'proveedores' => $proveedores,
            'tiposCargo' => DB::table('charge_type')->where(fn ($q) => $q->whereNull('deleted')->orWhere('deleted', 0))
                ->orderBy('charge_type_name')->pluck('charge_type_name', 'charge_type_id')->all(),
        ])->layout('components.app-layout', ['title' => $ruta->origen.' → '.$ruta->destino]);
    }
}
