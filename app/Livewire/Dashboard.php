<?php

namespace App\Livewire;

use App\Support\Dashboard\DashboardMetrics;
use App\Support\Dashboard\RouteMap;
use Livewire\Component;

/**
 * El panel de entrada: cómo va el mes y qué está esperando a alguien.
 *
 * Antes era una portada de la migración con tarjetas que decían «Etapa 3»,
 * «Etapa 4». Ahora enseña cifras del negocio y lleva de un clic a lo que hay que
 * atender.
 */
class Dashboard extends Component
{
    public function render()
    {
        return view('livewire.dashboard', [
            'panel' => app(DashboardMetrics::class)->all(auth()->user()?->isAdmin() ?? false),
            // Las rutas se piden aparte y sin caché: son doce filas y cambian
            // con cada captura de fecha; cachearlas dejaría el mapa desfasado
            // justo cuando alguien acaba de actualizar un embarque.
            'rutas' => RouteMap::rutas(),
        ])->layout('components.app-layout', ['title' => __('Panel')]);
    }
}
