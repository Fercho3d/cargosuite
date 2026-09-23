<?php

namespace App\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Las solicitudes de demostración que llegan por la página pública.
 *
 * Sin esta pantalla habría que entrar a la base por SSH para leerlas, y una
 * solicitud que nadie lee es un prospecto perdido.
 */
class DemoRequests extends Component
{
    use WithPagination;

    public bool $soloPendientes = true;

    public function mount(): void
    {
        // Sin portada pública no hay de dónde lleguen solicitudes: la pantalla
        // no existe en la instalación de un cliente.
        abort_unless(config('marca.landing'), 404);
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.app';
    }

    public function alternar(int $id): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        $fila = DB::table('solicitud_demo')->where('solicitud_id', $id)->first();

        abort_if($fila === null, 404);

        DB::table('solicitud_demo')->where('solicitud_id', $id)->update(['atendida' => ! $fila->atendida]);
    }

    public function updatedSoloPendientes(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $solicitudes = DB::table('solicitud_demo')
            ->when($this->soloPendientes, fn ($q) => $q->where('atendida', 0))
            ->orderByDesc('created_at')
            ->orderByDesc('solicitud_id')
            ->paginate(20);

        return view('livewire.demo-requests', [
            'solicitudes' => $solicitudes,
            'pendientes' => DB::table('solicitud_demo')->where('atendida', 0)->count(),
        ])->layout('components.app-layout', ['title' => __('Solicitudes de demostración')]);
    }
}
