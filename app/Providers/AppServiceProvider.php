<?php

namespace App\Providers;

use App\Support\Ajustes;
use App\Support\Cfdi\FacturacionModernaClient;
use App\Support\Cfdi\PacClient;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use ReflectionClass;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // El PAC se resuelve por interfaz para poder sustituirlo en pruebas: nada
        // de lo que se prueba debe salir a la red, y un timbrado de prueba contra
        // el PAC real consume folios.
        $this->app->bind(PacClient::class, FacturacionModernaClient::class);
    }

    /**
     * Vocabulario del negocio (`config/marca.php`).
     *
     * Las llaves de traducción SON el texto en español, así que renombrar el
     * dominio —«booking» → «orden de servicio»— es traducir. Basta con una
     * carpeta de traducciones más que gane sobre las de base.
     *
     * Dos cosas que costaron encontrar:
     *
     * · **`Lang::addJsonPath()` no sirve para sobrescribir.** Añade las rutas al
     *   PRINCIPIO: `FileLoader::loadJsonPaths()` recorre
     *   `array_merge($jsonPaths, $paths)` fusionando, y gana la última, así que
     *   lo añadido queda siempre por debajo de `lang/`. Aquí la carpeta del
     *   vocabulario va al final de `$paths`.
     * · **Va en `boot()`, no en `register()`.** Registrando, el proveedor de
     *   traducciones del framework todavía puede volver a poner el suyo encima
     *   y el vocabulario se pierde en silencio: la pantalla sigue diciendo
     *   «Bookings» y nada falla. Se rehace también el `translator`, porque el
     *   que ya esté armado guarda una referencia al cargador viejo.
     */
    private function registraVocabulario(): void
    {
        $vocabulario = trim((string) config('marca.vocabulario'));

        if ($vocabulario === '') {
            return;
        }

        $carpeta = lang_path('vocabulario/'.$vocabulario);

        if (! is_dir($carpeta)) {
            return;
        }

        // Primero se obliga al proveedor de traducciones a registrarse: es
        // DIFERIDO, así que si no, se carga más tarde —la primera vez que algo
        // pida el traductor— y vuelve a poner sus enlaces ENCIMA de estos.
        // Ese era el fallo, y era silencioso: la pantalla seguía en el
        // vocabulario de origen sin que nada avisara.
        $this->app->make('translation.loader');
        $this->app->make('translator');

        // La primera es la del propio framework (mensajes de validación); se
        // busca por reflexión para no escribir a mano una ruta de vendor.
        $delFramework = dirname((new ReflectionClass(FileLoader::class))->getFileName()).'/lang';

        $this->app->singleton('translation.loader', fn ($app) => new FileLoader(
            $app['files'],
            [$delFramework, $app['path.lang'], $carpeta],
        ));

        $this->app->singleton('translator', function ($app) {
            $traductor = new Translator($app['translation.loader'], $app->getLocale());
            $traductor->setFallback($app->getFallbackLocale());

            return $traductor;
        });

        Lang::clearResolvedInstance('translator');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Paginador propio: usa los tokens de tema en lugar de los grises fijos
        // de la vista que trae Laravel, que solo se ven bien en tema claro.
        // Ojo: los componentes Livewire NO heredan esto — cada uno declara su
        // `paginationView()`, porque Livewire vuelve a fijar el valor al pintar.
        Paginator::defaultView('vendor.pagination.app');
        Paginator::defaultSimpleView('vendor.pagination.app');

        // Los ajustes guardados mandan sobre el `.env`, y se aplican ANTES de
        // que nadie lea la configuración: el vocabulario de aquí abajo es el
        // primero que la consulta.
        Ajustes::aplicar();

        $this->registraVocabulario();
    }
}
