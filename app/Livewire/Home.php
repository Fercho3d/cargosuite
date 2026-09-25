<?php

namespace App\Livewire;

use App\Support\Marca;
use Livewire\Component;

/**
 * La página pública: qué hace el sistema y la entrada al login.
 */
class Home extends Component
{
    /**
     * Quien ya entró no ve la página de venta: se va a lo suyo. El personal al
     * sistema; el cliente y el proveedor, a su portal.
     */
    public function mount(): mixed
    {
        if (auth()->check()) {
            return redirect()->route(auth()->user()->isPortal() ? 'portal' : 'dashboard');
        }

        // Sin portada pública (instalación de cliente): la raíz va al login.
        if (! config('marca.landing')) {
            return redirect()->route('login');
        }

        return null;
    }

    public function render()
    {
        return view('livewire.home')->layout('components.public-layout', [
            'title' => Marca::nombre(),
        ]);
    }
}
