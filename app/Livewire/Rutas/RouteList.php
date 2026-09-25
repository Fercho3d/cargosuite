<?php

namespace App\Livewire\Rutas;

use App\Support\Rutas\Tarifario;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Configuración de rutas: cada origen → destino con sus kilómetros, lo que se
 * cobra, lo que cuesta y el margen. Aquí también se captura el diésel del día.
 */
class RouteList extends Component
{
    public bool $creando = false;

    public string $origen = '';

    public string $destino = '';

    public string $km = '';

    public string $horas = '';

    public string $rendimiento = '2.2';

    // --- Diésel del día ---
    public string $dieselFecha = '';

    public string $dieselPrecio = '';

    public function mount(): void
    {
        $this->assertAdmin();
        $this->dieselFecha = now()->toDateString();
    }

    private function assertAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);
    }

    public function crear()
    {
        $this->assertAdmin();

        $this->validate([
            'origen' => ['required', 'integer', 'exists:loading_ports,port_id'],
            'destino' => ['required', 'integer', 'exists:dicharge_port,dicharge_port_id',
                Rule::unique('ruta', 'destino_id')->where('origen_id', (int) $this->origen)],
            'km' => ['required', 'numeric', 'min:1'],
            'horas' => ['nullable', 'numeric', 'min:0'],
            'rendimiento' => ['required', 'numeric', 'min:0.5'],
        ], [
            'destino.unique' => __('Esa ruta ya existe.'),
        ], [
            'origen' => mb_strtolower(__('Origen')), 'destino' => mb_strtolower(__('Destino')),
            'km' => 'km', 'rendimiento' => mb_strtolower(__('Rendimiento')),
        ]);

        $id = DB::table('ruta')->insertGetId([
            'origen_id' => (int) $this->origen,
            'destino_id' => (int) $this->destino,
            'km' => (float) $this->km,
            'horas' => $this->horas === '' ? null : (float) $this->horas,
            'rendimiento' => (float) $this->rendimiento,
            'activo' => true,
        ], 'ruta_id');

        return $this->redirectRoute('rutas.show', ['ruta' => $id], navigate: true);
    }

    /**
     * Un precio por día: capturar otra vez la misma fecha la corrige, no la
     * duplica. Los días anteriores se quedan como estaban.
     */
    public function guardarDiesel(): void
    {
        $this->assertAdmin();

        $this->validate([
            'dieselFecha' => ['required', 'date', 'before_or_equal:today'],
            'dieselPrecio' => ['required', 'numeric', 'min:1', 'max:100'],
        ], attributes: ['dieselFecha' => mb_strtolower(__('Fecha')), 'dieselPrecio' => mb_strtolower(__('Precio por litro'))]);

        DB::table('precio_diesel')->updateOrInsert(
            ['fecha' => $this->dieselFecha],
            ['precio' => (float) $this->dieselPrecio, 'created_by' => auth()->id(), 'created_at' => now()],
        );

        $this->dieselPrecio = '';
        session()->flash('status', __('Precio del diésel guardado.'));
    }

    public function render()
    {
        $rutas = DB::table('ruta as r')
            ->join('loading_ports as o', 'o.port_id', '=', 'r.origen_id')
            ->join('dicharge_port as d', 'd.dicharge_port_id', '=', 'r.destino_id')
            ->orderBy('o.port_name')->orderBy('d.name')
            ->get(['r.*', 'o.port_name as origen', 'd.name as destino'])
            ->map(function (object $r) {
                $r->resumen = Tarifario::resumen($r);

                return $r;
            });

        return view('livewire.rutas.route-list', [
            'rutas' => $rutas,
            'diesel' => Tarifario::diesel(),
            'historialDiesel' => DB::table('precio_diesel')->orderByDesc('fecha')->limit(15)->get(),
            'origenes' => DB::table('loading_ports')->where('deleted', 0)->orderBy('port_name')->pluck('port_name', 'port_id')->all(),
            'destinos' => DB::table('dicharge_port')->where('deleted', 0)->orderBy('name')->pluck('name', 'dicharge_port_id')->all(),
        ])->layout('components.app-layout', ['title' => __('Configuración de rutas')]);
    }
}
