<?php

namespace App\Livewire;

use App\Support\Notifications\NoticeFeed;
use Livewire\Component;

/**
 * Bandeja de avisos: todo lo que está esperando a alguien, en una pantalla.
 *
 * Sustituye —o acompaña— al correo automático: los mismos avisos, sin llenarle
 * el buzón a nadie.
 */
class Notifications extends Component
{
    /** Grupo que se está viendo, o vacío para todos. */
    public string $grupo = '';

    /** @return array<string, string> */
    public function grupos(): array
    {
        return [
            'tareas' => __('Tareas vencidas'),
            'timbrado' => __('Facturas sin timbrar'),
            'sin_facturar' => __('Embarques sin facturar'),
            'sin_carga' => __('Embarques sin contenedores'),
            'pagos' => __('Solicitudes de pago abiertas'),
        ];
    }

    public function render()
    {
        $avisos = app(NoticeFeed::class)->all();

        return view('livewire.notifications', [
            'avisos' => $this->grupo === ''
                ? $avisos
                : $avisos->filter(fn ($aviso) => $aviso->grupo === $this->grupo)->values(),
            'conteos' => $avisos->countBy(fn ($aviso) => $aviso->grupo),
            'total' => $avisos->count(),
        ])->layout('components.app-layout', ['title' => __('Avisos')]);
    }
}
