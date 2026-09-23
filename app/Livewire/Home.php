<?php

namespace App\Livewire;

use App\Support\Marca;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * La página pública: qué hace el sistema y cómo pedir una demostración.
 *
 * La solicitud se guarda en la base y no solo se manda por correo: el correo
 * puede estar sin configurar o rebotar, y un prospecto perdido no se recupera.
 */
class Home extends Component
{
    #[Validate('required|string|min:2|max:100')]
    public string $nombre = '';

    #[Validate('nullable|string|max:100')]
    public string $empresa = '';

    #[Validate('required|email:filter|max:120')]
    public string $correo = '';

    #[Validate('nullable|string|max:40')]
    public string $telefono = '';

    #[Validate('nullable|string|max:500')]
    public string $mensaje = '';

    /**
     * Trampa para robots: un campo que una persona nunca ve ni llena. Si viene
     * con algo, se responde como si todo hubiera ido bien y no se guarda nada
     * — decirle al robot que lo detectaste solo le enseña a evitarlo.
     */
    public string $sitioWeb = '';

    public bool $enviada = false;

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

    public function solicitar(): void
    {
        $this->validate();

        if ($this->sitioWeb !== '') {
            $this->enviada = true;

            return;
        }

        // Un mismo origen no puede mandar más de tres al día: sin esto, la
        // primera tarde que alguien encuentre el formulario se llena de basura.
        $llave = 'demo:'.request()->ip();

        if (RateLimiter::tooManyAttempts($llave, 3)) {
            $this->addError('correo', __('Ya recibimos tu solicitud. Te contactamos en breve.'));

            return;
        }

        RateLimiter::hit($llave, 86400);

        DB::table('solicitud_demo')->insert([
            'nombre' => $this->nombre,
            'empresa' => $this->empresa ?: null,
            'correo' => $this->correo,
            'telefono' => $this->telefono ?: null,
            'mensaje' => $this->mensaje ?: null,
            'origen' => 'web',
            'created_at' => now(),
        ]);

        $this->enviada = true;
        $this->reset(['nombre', 'empresa', 'correo', 'telefono', 'mensaje']);
    }

    public function render()
    {
        return view('livewire.home')->layout('components.public-layout', [
            'title' => Marca::nombre(),
        ]);
    }
}
