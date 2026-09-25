<?php

namespace App\Livewire\Cotizaciones;

use App\Support\Cotizaciones\Cotizaciones;
use App\Support\Expediente;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Cotizaciones de viaje: la lista y el alta. Se cotiza sobre una ruta
 * configurada y los precios salen de ella; en la ficha se ajustan.
 */
class QuoteList extends Component
{
    use WithPagination;

    #[Url(as: 'estado', except: '')]
    public string $estado = '';

    public bool $creando = false;

    public string $cliente = '';

    public string $prospecto = '';

    public string $correo = '';

    public string $ruta = '';

    public string $fechaCarga = '';

    public function mount(): void
    {
        abort_unless(Expediente::usa('terrestre'), 404);
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $this->fechaCarga = now()->addDays(3)->toDateString();
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    public function updatedEstado(): void
    {
        $this->resetPage();
    }

    public function crear()
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $this->validate([
            'cliente' => ['nullable', 'required_without:prospecto', 'integer', 'exists:client,client_id'],
            'prospecto' => ['nullable', 'required_without:cliente', 'string', 'max:150'],
            'correo' => ['nullable', 'email', 'max:150'],
            'ruta' => ['required', 'integer', 'exists:ruta,ruta_id'],
            'fechaCarga' => ['nullable', 'date'],
        ], [
            'cliente.required_without' => __('Elige un cliente o escribe el nombre del prospecto.'),
            'prospecto.required_without' => __('Elige un cliente o escribe el nombre del prospecto.'),
        ], ['ruta' => mb_strtolower(__('Ruta')), 'correo' => mb_strtolower(__('Correo'))]);

        $cliente = $this->cliente === '' ? null : (int) $this->cliente;
        $fecha = $this->fechaCarga ?: null;

        $id = DB::transaction(function () use ($cliente, $fecha) {
            $id = DB::table('cotizacion')->insertGetId([
                'numero' => Cotizaciones::siguienteNumero(),
                'client_id' => $cliente,
                'prospecto' => $cliente === null ? trim($this->prospecto) : null,
                // Al cliente se le escribe a su correo de siempre si no dan otro.
                'correo' => ($this->correo ?: ($cliente ? DB::table('client')->where('client_id', $cliente)->value('email') : null)) ?: null,
                'ruta_id' => (int) $this->ruta,
                'fecha_carga' => $fecha,
                'vigencia' => now()->addDays(Cotizaciones::VIGENCIA_DIAS)->toDateString(),
                'estado' => 'borrador',
                'condiciones' => __('Precios en pesos mexicanos. El IVA y la retención se desglosan aparte. Estadías, maniobras extraordinarias y custodia se cotizan por separado. Sujeto a disponibilidad de unidad.'),
                'created_by' => auth()->id(),
                'created_at' => now(),
            ], 'cotizacion_id');

            foreach (Cotizaciones::renglonesDeRuta((int) $this->ruta, $fecha, $cliente) as $renglon) {
                DB::table('cotizacion_renglon')->insert($renglon + ['cotizacion_id' => $id]);
            }

            return $id;
        });

        return $this->redirectRoute('cotizaciones.show', ['cotizacion' => $id], navigate: true);
    }

    public function render()
    {
        $cotizaciones = DB::table('cotizacion as c')
            ->join('ruta as r', 'r.ruta_id', '=', 'c.ruta_id')
            ->join('loading_ports as o', 'o.port_id', '=', 'r.origen_id')
            ->join('dicharge_port as d', 'd.dicharge_port_id', '=', 'r.destino_id')
            ->leftJoin('client as cl', 'cl.client_id', '=', 'c.client_id')
            ->when($this->estado === 'vencida', fn ($q) => $q->where('c.estado', 'enviada')->where('c.vigencia', '<', now()->toDateString()))
            ->when($this->estado === 'enviada', fn ($q) => $q->where('c.estado', 'enviada')->where('c.vigencia', '>=', now()->toDateString()))
            ->when(! in_array($this->estado, ['', 'vencida', 'enviada'], true), fn ($q) => $q->where('c.estado', $this->estado))
            ->orderByDesc('c.cotizacion_id')
            ->select(['c.*', 'o.port_name as origen', 'd.name as destino', 'cl.fullName as cliente'])
            ->paginate(20);

        $totales = collect($cotizaciones->items())->mapWithKeys(fn (object $c) => [
            $c->cotizacion_id => Cotizaciones::totales(Cotizaciones::renglones((int) $c->cotizacion_id))['total'],
        ]);

        return view('livewire.cotizaciones.quote-list', [
            'cotizaciones' => $cotizaciones,
            'totales' => $totales,
            'clientes' => DB::table('client')->orderBy('fullName')->pluck('fullName', 'client_id')->all(),
            'rutas' => DB::table('ruta as r')
                ->join('loading_ports as o', 'o.port_id', '=', 'r.origen_id')
                ->join('dicharge_port as d', 'd.dicharge_port_id', '=', 'r.destino_id')
                ->where('r.activo', 1)->orderBy('o.port_name')->orderBy('d.name')
                ->get(['r.ruta_id', 'o.port_name', 'd.name'])
                ->mapWithKeys(fn ($r) => [$r->ruta_id => $r->port_name.' → '.$r->name])->all(),
        ])->layout('components.app-layout', ['title' => __('Cotizaciones')]);
    }
}
