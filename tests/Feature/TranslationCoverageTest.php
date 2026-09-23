<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Que no queden textos en español cuando la interfaz está en inglés.
 *
 * Las llaves de traducción SON el texto en español, así que una llave que no
 * esté en `lang/en.json` no falla: se pinta en español y nadie se entera. Esta
 * prueba recorre el código y avisa.
 *
 * También vigila el error que ya se coló una vez: la automatización de
 * traducción envolvió en `__()` la CLAVE de un arreglo en vez de su ETIQUETA,
 * así que las cabeceras salían en español y —peor— la clave viajaba traducida.
 */
class TranslationCoverageTest extends TestCase
{
    /**
     * Códigos y ejemplos que se dejan igual a propósito: no son texto.
     *
     * @var string[]
     */
    private const SIN_TRADUCIR = [
        'Booking', 'CFDI', 'UUID', 'IVA', 'TC', 'RFC', 'BL', 'SWB', 'VGM', 'SAT',
        'MEX…', 'F-1234', 'xxxxxxxx-xxxxxxxx',
        'The provided password does not match your current password.',
    ];

    /** @return string[] */
    private function fuentes(): array
    {
        $archivos = [];

        foreach ([base_path('app'), resource_path('views')] as $raiz) {
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz));

            foreach ($rii as $archivo) {
                if (! $archivo->isDir() && str_ends_with($archivo->getFilename(), '.php')) {
                    $archivos[] = $archivo->getPathname();
                }
            }
        }

        return $archivos;
    }

    public function test_toda_llave_usada_tiene_traduccion_al_ingles(): void
    {
        $ingles = json_decode(file_get_contents(lang_path('en.json')), true);

        $huerfanas = [];

        foreach ($this->fuentes() as $archivo) {
            preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*[,)]/", file_get_contents($archivo), $coincidencias);

            foreach ($coincidencias[1] as $llave) {
                $llave = str_replace("\\'", "'", $llave);

                if ($llave === '' || in_array($llave, self::SIN_TRADUCIR, true) || isset($ingles[$llave])) {
                    continue;
                }

                // Llave de GRUPO (`impresos.pick_up_place`): esas no viven en
                // `en.json` sino en `lang/{idioma}/impresos.php`. Los documentos
                // impresos tienen su propio archivo porque replican el inglés del
                // sistema anterior —erratas incluidas— y compartir llaves con la
                // interfaz hacía que traducir una pantalla rompiera un documento.
                if (preg_match('/^([a-z][a-z_]*)\.([a-z][a-z_0-9]*)$/', $llave, $partes)) {
                    $grupo = $partes[1];
                    $item = $partes[2];

                    foreach (['es', 'en'] as $idioma) {
                        $ruta = lang_path("{$idioma}/{$grupo}.php");

                        $this->assertFileExists($ruta, "Falta el archivo de traducción del grupo «{$grupo}».");
                        $this->assertArrayHasKey(
                            $item,
                            require $ruta,
                            "«{$llave}» no está en lang/{$idioma}/{$grupo}.php.",
                        );
                    }

                    continue;
                }

                $huerfanas[$llave] = basename($archivo);
            }
        }

        $this->assertSame(
            [],
            $huerfanas,
            "Estas llaves se pintarían en español con la interfaz en inglés:\n  ".
            implode("\n  ", array_map(fn ($f, $k) => "$k  ($f)", $huerfanas, array_keys($huerfanas)))
        );
    }

    /**
     * 🛡️ El hueco que este guardián no veía: **un literal que nunca pasó por
     * `__()`**.
     *
     * La prueba de arriba comprueba que toda llave USADA tenga traducción, así
     * que un texto escrito a pelo —`$editando ? 'Editar booking' : 'Nuevo
     * booking'`— se le escapa entero: no es una llave, es una cadena. Se
     * colaron doce así, y ni el inglés ni el vocabulario del negocio los tocaban.
     *
     * Se busca **por posición y no por idioma**: los rótulos de alta y edición
     * siempre empiezan por «Nuevo», «Nueva» o «Editar», lleven acentos o no.
     */
    public function test_ningun_rotulo_de_alta_o_edicion_se_quedo_sin_envolver(): void
    {
        $sueltos = [];

        foreach ($this->fuentes() as $archivo) {
            $contenido = (string) file_get_contents($archivo);

            preg_match_all("/'((?:Nuev[oa]|Editar) [^']{2,40})'/u", $contenido, $coincidencias, PREG_OFFSET_CAPTURE);

            foreach ($coincidencias[0] as [$literal, $posicion]) {
                // Lo que ya va dentro de `__(` está bien: se busca lo de fuera.
                //
                // ⚠️ `substr` y no `mb_substr`: PREG_OFFSET_CAPTURE devuelve el
                // desplazamiento en BYTES aunque el patrón lleve /u, y mezclarlo
                // con una función de caracteres recorta en el sitio equivocado
                // en cuanto hay un acento antes.
                $antes = substr($contenido, max(0, $posicion - 4), 4);

                if (str_contains($antes, '__(')) {
                    continue;
                }

                $sueltos[] = basename($archivo).' → '.$literal;
            }
        }

        $this->assertSame([], $sueltos, "Estos rótulos no pasan por __() y nunca se traducen:\n  ".implode("\n  ", $sueltos));
    }

    /**
     * 🛡️ Y el hueco que este otro guardián tampoco veía: **un literal que YA es
     * una llave conocida**, escrito a pelo dentro de un `{{ }}`.
     *
     * El de arriba solo mira los rótulos que empiezan por «Nuevo» o «Editar».
     * Se coló `$mode === '9' ? 'Cotizaciones' : 'Bookings'`, y con él el
     * encabezado de la pantalla de operación: en una instalación de camiones,
     * donde el vocabulario convierte «Bookings» en «Viajes», el título seguía
     * diciendo «Bookings». Ocho más estaban igual.
     *
     * Se busca contra el diccionario y no contra el idioma: si el texto ya tiene
     * traducción, escribirlo sin `__()` es siempre un error —o sobra la llave—.
     */
    public function test_ningun_texto_ya_traducible_se_escribe_a_pelo(): void
    {
        $llaves = json_decode((string) file_get_contents(lang_path('en.json')), true);
        $sueltos = [];

        foreach ($this->blades() as $archivo) {
            $contenido = (string) file_get_contents($archivo);

            // Solo lo que se PINTA: un literal en un `@php` o en una clave de
            // arreglo no llega a la pantalla.
            preg_match_all('/\{\{(.*?)\}\}/s', $contenido, $echos);

            foreach ($echos[1] as $echo) {
                // ⚠️ Se aceptan literales de UN carácter aunque nunca sean
                // llaves: si no, `'9'` no empareja y el escáner arranca la
                // pareja de comillas a destiempo, se traga el resto del renglón
                // y da por bueno justo el caso que se busca. Se descartan
                // después, contra el diccionario.
                preg_match_all("/(__\(|trans_choice\(|\[)?'([^']{1,80})'/", $echo, $literales, PREG_SET_ORDER);

                foreach ($literales as $literal) {
                    if (($literal[1] ?? '') !== '' || ! array_key_exists($literal[2], $llaves)) {
                        continue;
                    }

                    $sueltos[] = basename($archivo).' → '.$literal[2];
                }
            }
        }

        $this->assertSame([], $sueltos,
            "Estos textos ya tienen traducción pero se pintan sin __(), así que nunca se traducen:\n  ".implode("\n  ", $sueltos));
    }

    /** @return list<string> */
    private function blades(): array
    {
        $vistas = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $archivo) {
            if (str_ends_with((string) $archivo, '.blade.php')) {
                $vistas[] = (string) $archivo;
            }
        }

        return $vistas;
    }

    /**
     * 🐛 Un archivo de traducción por grupo **eclipsa la llave de interfaz que se
     * llame igual**, y devuelve el arreglo entero en vez del texto: la pantalla
     * revienta con «htmlspecialchars(): must be of type string, array given».
     *
     * Pasó con `lang/es/documento.php`, que dejó a `__('Documento')` devolviendo
     * un arreglo y tumbó 38 pruebas. Y **en macOS ni siquiera hace falta que
     * coincidan las mayúsculas**: el sistema de archivos no distingue, así que
     * `Documento` encuentra `documento.php`. En el Linux de producción sí
     * distingue, o sea que este fallo se comporta distinto en cada sitio — razón
     * de más para prohibirlo de raíz.
     */
    public function test_ningun_archivo_de_grupo_eclipsa_una_llave_de_la_interfaz(): void
    {
        $llaves = array_map('mb_strtolower', array_keys(
            json_decode((string) file_get_contents(lang_path('en.json')), true)
        ));

        foreach (['es', 'en'] as $idioma) {
            foreach (glob(lang_path($idioma.'/*.php')) ?: [] as $archivo) {
                $grupo = mb_strtolower(basename($archivo, '.php'));

                $this->assertNotContains(
                    $grupo,
                    $llaves,
                    "El archivo de grupo «{$grupo}.php» se llama igual que una llave de la interfaz: ".
                    "`__('{$grupo}')` devolvería el arreglo entero y reventaría la pantalla."
                );
            }
        }
    }

    /**
     * `__()` va en la etiqueta, nunca en la clave: una clave traducida deja de
     * coincidir con el valor que se compara (`$screen === $key`) y rompe la
     * pantalla en cuanto alguien traduce esa palabra.
     */
    public function test_ninguna_clave_de_arreglo_va_traducida(): void
    {
        $sospechosas = [];

        foreach ($this->fuentes() as $archivo) {
            if (preg_match_all("/\[__\('[a-z_]+'\)\s*,/", file_get_contents($archivo), $coincidencias)) {
                $sospechosas[basename($archivo)] = $coincidencias[0];
            }
        }

        $this->assertSame([], $sospechosas, 'Hay `__()` envolviendo una clave de arreglo en vez de una etiqueta.');
    }
}
