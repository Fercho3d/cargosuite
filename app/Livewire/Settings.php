<?php

namespace App\Livewire;

use App\Support\Ajustes;
use App\Support\Expediente;
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

    public bool $timbrado = false;

    public string $vocabulario = '';

    public string $idiomaDocumentos = 'en';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        $this->modalidades = Expediente::modalidades();
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
            'timbrado.habilitado' => $this->timbrado,
            'marca.vocabulario' => $this->vocabulario,
            'marca.idioma_documentos' => $this->idiomaDocumentos,
        ], auth()->id());

        session()->flash('status', __('Ajustes guardados. Vuelve a cargar para ver los cambios en el menú.'));
    }

    public function render()
    {
        return view('livewire.settings')
            ->layout('components.app-layout', ['title' => __('Ajustes')]);
    }
}
