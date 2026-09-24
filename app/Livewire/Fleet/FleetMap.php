<?php

namespace App\Livewire\Fleet;

use App\Support\Expediente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Mapa de la flota: la última posición que reportó el GPS de cada unidad.
 *
 * Se refresca solo cada 30 s (`wire:poll`). Los puntos viajan al navegador en
 * `$puntos`; el mapa (Leaflet) los vuelve a pintar cada vez que cambian.
 */
class FleetMap extends Component
{
    public string $buscar = '';

    /** '' | movimiento | detenida | sin_senal */
    public string $estado = '';

    public bool $soloEnViaje = false;

    /** @var list<array<string, mixed>> */
    public array $puntos = [];

    public function mount(): void
    {
        abort_unless(Expediente::usa('terrestre'), 404);
    }

    /** @return list<array<string, mixed>> */
    private function unidades(): array
    {
        // El viaje en curso de cada unidad: el más reciente que no esté cerrado.
        $viajes = DB::table('booking as b')
            ->leftJoin('operador as o', 'o.operador_id', '=', 'b.operador_id')
            ->leftJoin('client as c', 'c.client_id', '=', 'b.client')
            ->whereNotNull('b.unidad_id')->where('b.is_draft', 0)->where('b.locked', 0)
            ->orderByDesc('b.booking_id')
            ->get(['b.booking_id', 'b.unidad_id', 'b.booking_number', 'o.nombre as operador', 'c.fullName as cliente'])
            ->unique('unidad_id')->keyBy('unidad_id');

        $limite = now()->subMinutes((int) config('gps.sin_senal_minutos'));

        return DB::table('gps_dispositivo as d')
            ->join('unidad as u', 'u.unidad_id', '=', 'd.unidad_id')
            ->where('d.activo', 1)->whereNotNull('d.ultima_lat')->whereNotNull('d.ultima_senal')
            ->orderBy('u.numero')
            ->get(['u.unidad_id', 'u.numero', 'u.placas', 'd.ultima_lat', 'd.ultima_lng', 'd.ultima_velocidad', 'd.ultimo_rumbo', 'd.ultima_senal'])
            ->map(function (object $f) use ($viajes, $limite) {
                $senal = Carbon::parse($f->ultima_senal);
                $velocidad = (float) $f->ultima_velocidad;
                $viaje = $viajes->get($f->unidad_id);

                return [
                    'id' => (int) $f->unidad_id,
                    'numero' => (string) $f->numero,
                    'placas' => (string) $f->placas,
                    'lat' => (float) $f->ultima_lat,
                    'lng' => (float) $f->ultima_lng,
                    'velocidad' => round($velocidad),
                    'rumbo' => $f->ultimo_rumbo === null ? null : (int) $f->ultimo_rumbo,
                    'senal' => $senal->diffForHumans(),
                    'estado' => match (true) {
                        $senal->lt($limite) => 'sin_senal',
                        $velocidad >= (float) config('gps.velocidad_detenida') => 'movimiento',
                        default => 'detenida',
                    },
                    'viaje' => $viaje?->booking_number,
                    'viajeUrl' => $viaje ? route('operations.bookings.show', $viaje->booking_id) : null,
                    'operador' => $viaje?->operador,
                    'cliente' => $viaje?->cliente,
                ];
            })
            ->all();
    }

    public function render()
    {
        $todas = collect($this->unidades());
        $buscar = mb_strtolower(trim($this->buscar));

        $this->puntos = $todas
            ->when($this->estado !== '', fn ($c) => $c->where('estado', $this->estado))
            ->when($this->soloEnViaje, fn ($c) => $c->whereNotNull('viaje'))
            ->when($buscar !== '', fn ($c) => $c->filter(fn ($u) => str_contains(mb_strtolower($u['numero'].' '.$u['placas'].' '.$u['operador'].' '.$u['viaje']), $buscar)))
            ->values()->all();

        return view('livewire.fleet.fleet-map', [
            'cuenta' => $todas->countBy('estado'),
            'total' => $todas->count(),
        ])->layout('components.app-layout', ['title' => __('Mapa de la flota')]);
    }
}
