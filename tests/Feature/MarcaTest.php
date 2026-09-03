<?php

namespace Tests\Feature;

use App\Support\Marca;
use Tests\TestCase;

/**
 * La marca es lo único que se cambia al instalar el sistema para otro cliente,
 * así que conviene que esté sujeta: si alguien vuelve a escribir un nombre o un
 * color dentro de una vista, esto se pone rojo.
 */
class MarcaTest extends TestCase
{
    public function test_la_pantalla_de_acceso_toma_el_nombre_de_la_configuracion(): void
    {
        config([
            'marca.nombre' => 'Marca Inventada',
            'marca.lema' => 'Un lema cualquiera',
            'marca.pie' => 'Aviso del pie',
        ]);

        $respuesta = $this->get('/login');

        $respuesta->assertOk();
        $respuesta->assertSee('Marca Inventada');
        $respuesta->assertSee('Un lema cualquiera');
        $respuesta->assertSee('Aviso del pie');
    }

    public function test_el_logotipo_de_letra_sale_de_la_configuracion(): void
    {
        config([
            'marca.logo.imagen.claro' => null,
            'marca.logo.texto.principal' => 'Alfa',
            'marca.logo.texto.acento' => 'Beta',
        ]);

        $this->get('/login')
            ->assertSee('>Alfa</span>', false)
            ->assertSee('Beta', false);
    }

    public function test_con_imagen_configurada_deja_de_dibujarse_el_logotipo_de_letra(): void
    {
        config([
            'marca.logo.imagen.claro' => 'marca/mi-logo.svg',
            'marca.logo.texto.principal' => 'NoDeberiaSalir',
        ]);

        $respuesta = $this->get('/login');

        $respuesta->assertSee('marca/mi-logo.svg', false);
        $respuesta->assertDontSee('NoDeberiaSalir');
    }

    public function test_sin_version_oscura_la_imagen_clara_vale_para_los_dos_temas(): void
    {
        config(['marca.logo.imagen.claro' => 'marca/logo.svg', 'marca.logo.imagen.oscuro' => null]);

        $this->assertFalse(Marca::tieneLogoOscuro());
        $this->assertSame(Marca::logo('claro'), Marca::logo('oscuro'));
    }

    public function test_el_color_de_acento_se_pinta_como_variables_css(): void
    {
        config(['marca.colores.acento_500' => '#123456', 'marca.colores.marca_oscuro' => '#abcdef']);

        $this->get('/login')
            ->assertSee('--color-accent-500:#123456', false)
            ->assertSee('.dark{--brand:#abcdef;}', false);
    }

    /**
     * El color viene del `.env`, o sea de fuera del código, y termina dentro de
     * una etiqueta <style>. Un valor con `}` podría escribir CSS arbitrario en
     * todas las pantallas, así que se descarta lo que no tenga forma de color.
     */
    public function test_un_color_con_forma_rara_se_descarta_en_vez_de_colarse_en_la_hoja(): void
    {
        config(['marca.colores.acento_500' => '#fff}body{display:none']);

        $this->assertStringNotContainsString('display:none', Marca::estilos());
    }

    public function test_el_portal_cae_en_la_direccion_de_la_aplicacion_si_no_se_configura(): void
    {
        config(['marca.empresa.portal' => '', 'app.url' => 'https://ejemplo.test']);

        $this->assertSame('https://ejemplo.test/portal', Marca::portal());
    }

    public function test_la_ficha_impresa_no_pinta_los_renglones_vacios(): void
    {
        config([
            'marca.empresa.domicilio' => 'Calle Uno|Colonia Dos',
            'marca.empresa.rfc' => '',
            'marca.empresa.telefono' => '55 1234',
        ]);

        $membrete = view('pdf.membrete')->render();

        $this->assertStringContainsString('Calle Uno', $membrete);
        $this->assertStringContainsString('Colonia Dos', $membrete);
        $this->assertStringContainsString('Tel: 55 1234', $membrete);
        $this->assertStringNotContainsString('RFC:', $membrete);
    }

    /**
     * Guardián: el nombre de la empresa que encargó el sistema no puede volver
     * a aparecer escrito en una vista. Si vuelve, es que alguien lo puso a mano
     * en vez de sacarlo de la configuración.
     */
    public function test_ninguna_vista_lleva_el_nombre_del_primer_cliente_escrito_a_mano(): void
    {
        $encontradas = [];

        $vistas = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($vistas as $archivo) {
            if ($archivo->getExtension() !== 'php') {
                continue;
            }

            $contenido = (string) file_get_contents($archivo->getPathname());

            if (preg_match('/frego/i', $contenido)) {
                $encontradas[] = str_replace(resource_path('views').'/', '', $archivo->getPathname());
            }
        }

        $this->assertSame([], $encontradas, 'Estas vistas traen la marca escrita a mano: '.implode(', ', $encontradas));
    }
}
