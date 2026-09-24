<?php

namespace App\Livewire;

use App\Actions\Demo\RellenaDatosDemo;
use App\Support\Ajustes;
use App\Support\Expediente;
use App\Support\Marca;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Ajustes de la instalación.
 *
 * Aquí se elige **cómo mueve la empresa** —marítimo, terrestre o las dos— y un
 * puñado de interruptores que antes solo se podían cambiar entrando por SSH al
 * servidor. Para un sistema que se instala en casa de otros, eso no servía.
 */
class Settings extends Component
{
    use WithFileUploads;

    /** @var list<string> */
    public array $modalidades = [];

    public bool $taller = false;

    public bool $nomina = false;

    public bool $timbrado = false;

    public string $vocabulario = '';

    public string $idiomaDocumentos = 'en';

    /** El color de marca; los demás tonos salen de él (`Marca::paleta()`). */
    public string $color = '';

    public string $logoPrincipal = '';

    public string $logoAcento = '';

    public ?TemporaryUploadedFile $logoClaro = null;

    public ?TemporaryUploadedFile $logoOscuro = null;

    public bool $quitarLogo = false;

    public function mount(): void
    {
        abort_unless(config('marca.ajustes'), 404);
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        $this->modalidades = array_values(array_intersect(Expediente::modalidades(), self::disponibles())) ?: self::disponibles();
        $this->taller = (bool) config('marca.taller');
        $this->nomina = (bool) config('marca.nomina');
        $this->timbrado = (bool) config('timbrado.habilitado');
        $this->vocabulario = (string) config('marca.vocabulario');
        $this->idiomaDocumentos = (string) config('marca.idioma_documentos');
        $this->color = (string) config('marca.colores.acento_500');
        $this->logoPrincipal = (string) config('marca.logo.texto.principal');
        $this->logoAcento = (string) config('marca.logo.texto.acento');
    }

    /**
     * Las formas de transporte que se ofrecen aquí.
     *
     * @return list<string>
     */
    public static function disponibles(): array
    {
        $elegidas = array_values(array_intersect(
            ['maritimo', 'terrestre'],
            array_map('trim', explode(',', (string) config('marca.modalidades_disponibles'))),
        ));

        return $elegidas === [] ? ['maritimo', 'terrestre'] : $elegidas;
    }

    /**
     * Sin lo marítimo, tampoco sus vocabularios: el de origen y «embarques».
     *
     * @return list<string>
     */
    public function vocabularios(): array
    {
        return collect(glob(lang_path('vocabulario/*'), GLOB_ONLYDIR) ?: [])
            ->map(fn (string $ruta) => basename($ruta))
            ->reject(fn (string $v) => $v === 'embarques' && ! in_array('maritimo', self::disponibles(), true))
            ->values()
            ->all();
    }

    public function guardar(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() ?? false, 403);

        $this->validate([
            'modalidades' => ['required', 'array', 'min:1'],
            'modalidades.*' => ['in:'.implode(',', self::disponibles())],
            'vocabulario' => ['nullable', 'string', 'max:40'],
            'idiomaDocumentos' => ['in:es,en'],
            // Termina dentro de un <style>: solo #rrggbb, nada que cierre la regla.
            ...(config('marca.editar_marca') ? [
                'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'logoPrincipal' => ['required', 'string', 'max:30'],
                'logoAcento' => ['nullable', 'string', 'max:30'],
                // Sin SVG: puede llevar código y se sirve desde el mismo dominio.
                'logoClaro' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
                'logoOscuro' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            ] : []),
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
            // Sin el apartado a la vista no se guarda: si no, se pisarían los
            // tonos que se pusieron a mano en el `.env` con los calculados.
            ...(config('marca.editar_marca') ? [
                'marca.logo.texto.principal' => $this->logoPrincipal,
                'marca.logo.texto.acento' => (string) $this->logoAcento,
                ...collect(Marca::paleta($this->color))->mapWithKeys(fn ($v, $k) => ["marca.colores.$k" => $v]),
                ...$this->imagenesDelLogo(),
            ] : []),
        ], auth()->id());

        $this->reset('logoClaro', 'logoOscuro', 'quitarLogo');

        session()->flash('status', __('Ajustes guardados. Vuelve a cargar para ver los cambios en el menú.'));
    }

    /**
     * Qué imagen del logotipo cambia. Lo que no se sube se queda como está;
     * «quitar» borra las dos y vuelve al logotipo de letra.
     *
     * @return array<string, string>
     */
    private function imagenesDelLogo(): array
    {
        if ($this->quitarLogo) {
            return ['marca.logo.imagen.claro' => '', 'marca.logo.imagen.oscuro' => ''];
        }

        return collect(['claro' => $this->logoClaro, 'oscuro' => $this->logoOscuro])
            ->filter()
            ->mapWithKeys(fn (TemporaryUploadedFile $archivo, string $tema) => [
                "marca.logo.imagen.$tema" => 'storage/'.$archivo->store('marca', 'public'),
            ])
            ->all();
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
        abort_unless(in_array($vertical, self::disponibles(), true), 403);

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
            'disponibles' => self::disponibles(),
            'vertical' => RellenaDatosDemo::permitido() ? RellenaDatosDemo::vertical() : null,
        ])->layout('components.app-layout', ['title' => __('Ajustes')]);
    }
}
