<?php

namespace App\Livewire;

use App\Actions\Demo\RellenaDatosDemo;
use App\Support\Ajustes;
use App\Support\Expediente;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Ajustes de la instalación.
 *
 * Aquí se elige **cómo mueve la empresa** —marítimo, terrestre o las dos— y un
 * puñado de interruptores que antes solo se podían cambiar entrando por SSH al
 * servidor. Para un sistema que se instala en casa de otros, eso no servía.
 */
class Settings extends Component
{
    /** @var list<string> */
    public array $modalidades = [];

    public bool $taller = false;

    public bool $nomina = false;

    public bool $timbrado = false;

    public string $vocabulario = '';

    public string $idiomaDocumentos = 'en';

    public function mount(): void
    {
        abort_unless(config('marca.ajustes'), 404);
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        $this->modalidades = Expediente::modalidades();
        $this->taller = (bool) config('marca.taller');
        $this->nomina = (bool) config('marca.nomina');
        $this->timbrado = (bool) config('timbrado.habilitado');
        $this->vocabulario = (string) config('marca.vocabulario');
        $this->idiomaDocumentos = (string) config('marca.idioma_documentos');
    }

    /** @return list<string> */
    public function vocabularios(): array
    {
        return collect(glob(lang_path('vocabulario/*'), GLOB_ONLYDIR) ?: [])
            ->map(fn (string $ruta) => basename($ruta))
            ->values()
            ->all();
    }

    public function guardar(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        $this->validate([
            'modalidades' => ['required', 'array', 'min:1'],
            'modalidades.*' => ['in:maritimo,terrestre'],
            'vocabulario' => ['nullable', 'string', 'max:40'],
            'idiomaDocumentos' => ['in:es,en'],
        ], [
            // Sin modalidad, el expediente se queda sin medio de transporte.
            'modalidades.required' => __('Elige al menos una forma de transporte.'),
            'modalidades.min' => __('Elige al menos una forma de transporte.'),
        ], attributes: [
            'modalidades' => mb_strtolower(__('Forma de transporte')),
        ]);

        Ajustes::guardar([
            'marca.modalidades' => $this->modalidades,
            'marca.taller' => $this->taller,
            'marca.nomina' => $this->nomina,
            'timbrado.habilitado' => $this->timbrado,
            'marca.vocabulario' => $this->vocabulario,
            'marca.idioma_documentos' => $this->idiomaDocumentos,
        ], auth()->id());

        session()->flash('status', __('Ajustes guardados. Vuelve a cargar para ver los cambios en el menú.'));
    }

    /**
     * Rellena la base con los datos de una vertical, para enseñar el sistema.
     *
     * Después se cierra la sesión a propósito: el sembrador vacía la tabla de
     * usuarios y la vuelve a llenar, así que la sesión abierta apunta a una
     * cuenta que ya no es la misma aunque conserve el número.
     */
    public function rellenar(string $vertical): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);
        abort_unless(RellenaDatosDemo::permitido(), 403);

        app(RellenaDatosDemo::class)($vertical, auth()->id());

        Auth::guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();
        session()->flash('status', __('Datos de demostración listos. Vuelve a entrar.'));

        $this->redirect(route('login'));
    }

    public function render()
    {
        return view('livewire.settings', [
            'demo' => RellenaDatosDemo::permitido(),
            'vertical' => RellenaDatosDemo::permitido() ? RellenaDatosDemo::vertical() : null,
        ])->layout('components.app-layout', ['title' => __('Ajustes')]);
    }
}
