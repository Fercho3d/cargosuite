<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * El vocabulario del negocio: cómo se llama cada cosa en esta instalación.
 *
 * Las llaves de traducción SON el texto en español, así que renombrar el dominio
 * —«booking» → «orden de servicio»— es traducir. Estas pruebas fijan las dos
 * cosas que pueden salir mal sin que nadie se entere: que la capa no gane sobre
 * la base, y que una llave con una errata deje el texto sin cambiar.
 */
class VocabularioTest extends TestCase
{
    /**
     * Una llave mal tecleada no falla: simplemente no traduce nada, y el texto
     * viejo se queda en pantalla. Por eso se comprueba contra la base.
     */
    public function test_todas_las_llaves_del_vocabulario_existen_en_la_base(): void
    {
        $base = array_keys(json_decode((string) file_get_contents(lang_path('en.json')), true));
        $revisados = 0;

        foreach (glob(lang_path('vocabulario/*'), GLOB_ONLYDIR) ?: [] as $carpeta) {
            foreach (['es', 'en'] as $idioma) {
                $archivo = $carpeta.'/'.$idioma.'.json';

                if (! file_exists($archivo)) {
                    continue;
                }

                $llaves = array_keys(json_decode((string) file_get_contents($archivo), true) ?: []);

                $this->assertSame(
                    [],
                    array_values(array_diff($llaves, $base)),
                    'El vocabulario `'.basename($carpeta).'/'.$idioma.'` tiene llaves que no existen en la base.',
                );

                $revisados++;
            }
        }

        $this->assertGreaterThan(0, $revisados, 'No se revisó ningún vocabulario.');
    }

    public function test_los_dos_idiomas_de_un_vocabulario_cubren_lo_mismo(): void
    {
        foreach (glob(lang_path('vocabulario/*'), GLOB_ONLYDIR) ?: [] as $carpeta) {
            $es = json_decode((string) @file_get_contents($carpeta.'/es.json'), true) ?: [];
            $en = json_decode((string) @file_get_contents($carpeta.'/en.json'), true) ?: [];

            if ($es === [] || $en === []) {
                continue;
            }

            $this->assertSame(
                array_keys($es),
                array_keys($en),
                'El vocabulario `'.basename($carpeta).'` traduce cosas distintas en cada idioma.',
            );
        }
    }

    /**
     * Lo que de verdad hay que comprobar: que la capa GANE sobre la base.
     *
     * `Lang::addJsonPath()` no sirve para esto —añade las rutas por debajo— y el
     * fallo sería silencioso: la pantalla seguiría diciendo «Bookings».
     */
    public function test_el_vocabulario_gana_sobre_la_traduccion_de_base(): void
    {
        $this->assertSame('Bookings', __('Bookings'), 'Sin vocabulario manda el texto de origen.');

        $this->conVocabulario('servicios', function (): void {
            $this->assertSame('Órdenes de servicio', __('Bookings'));
            $this->assertSame('Equipos', __('Contenedores'));

            app()->setLocale('en');
            $this->assertSame('Service orders', __('Bookings'));
        });
    }

    /** Un vocabulario que no existe no puede tumbar la aplicación. */
    public function test_un_vocabulario_inexistente_se_ignora(): void
    {
        $this->conVocabulario('no-existe', function (): void {
            $this->assertSame('Bookings', __('Bookings'));
            $this->get('/login')->assertOk();
        });
    }

    /**
     * Levanta la aplicación otra vez con otro vocabulario.
     *
     * Tiene que ir por el entorno y no por `config()`: el cargador de
     * traducciones se arma en `register()`, o sea antes de que una prueba pueda
     * tocar nada, y `refreshApplication()` vuelve a leer los archivos de
     * configuración —así que un `config([...])` puesto antes se pierde—.
     */
    private function conVocabulario(string $vocabulario, callable $prueba): void
    {
        $_ENV['MARCA_VOCABULARIO'] = $_SERVER['MARCA_VOCABULARIO'] = $vocabulario;

        try {
            $this->refreshApplication();
            $prueba();
        } finally {
            unset($_ENV['MARCA_VOCABULARIO'], $_SERVER['MARCA_VOCABULARIO']);
            $this->refreshApplication();
        }
    }
}
