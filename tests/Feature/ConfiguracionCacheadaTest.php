<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Lang;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * 🐛 `env()` fuera de `config/` **no vale nada en producción**.
 *
 * Con la configuración cacheada —`artisan optimize`, que es como corre
 * producción— Laravel ni siquiera abre el `.env`: `LoadEnvironmentVariables` se
 * salta entero. Un `env('LO_QUE_SEA')` a la hora de ejecutar devuelve entonces
 * el valor de fábrica, en silencio y solo allá.
 *
 * Costó caro: el botón que rellena la demostración creaba las cinco cuentas con
 * `env('DEMO_PASSWORD')`, o sea con la clave de fábrica en vez de la de la
 * instalación, y dejó a todo el mundo fuera del sistema. En local no pasaba,
 * porque en local la configuración no está cacheada.
 *
 * La regla es la de Laravel de siempre: `env()` SOLO dentro de `config/`, y todo
 * lo demás por `config()`.
 */
class ConfiguracionCacheadaTest extends TestCase
{
    public function test_nadie_llama_a_env_fuera_de_config(): void
    {
        $sueltos = [];

        foreach (['app', 'database', 'routes', 'resources/views'] as $carpeta) {
            foreach ($this->fuentes(base_path($carpeta)) as $archivo) {
                $contenido = (string) file_get_contents($archivo);

                $llamadas = $this->llamadasAEnv($contenido);

                if ($llamadas > 0) {
                    $sueltos[] = str_replace(base_path().'/', '', $archivo).' ('.$llamadas.')';
                }
            }
        }

        $this->assertSame([], $sueltos,
            "Con la configuración cacheada esto lee el valor de fábrica y no el del `.env`:\n  "
            .implode("\n  ", $sueltos));
    }

    /**
     * Y el otro lado de lo mismo: sin archivo de grupo, el framework enseña la
     * LLAVE. La pantalla de entrada decía «auth.failed» al fallar el acceso.
     */
    public function test_los_mensajes_de_autenticacion_estan_traducidos(): void
    {
        foreach (['es', 'en'] as $idioma) {
            foreach (['failed', 'password', 'throttle'] as $llave) {
                $mensaje = Lang::get('auth.'.$llave, [], $idioma);

                $this->assertNotSame('auth.'.$llave, $mensaje,
                    "`auth.{$llave}` en {$idioma} sale como llave y no como mensaje.");
            }
        }
    }

    /**
     * Cuántas veces se LLAMA a `env()` de verdad.
     *
     * Con el tokenizador y no con una expresión regular: el aviso de este mismo
     * problema, escrito en un comentario encima de la línea que lo arregla, se
     * contaba como una infracción.
     */
    private function llamadasAEnv(string $contenido): int
    {
        $tokens = token_get_all($contenido);
        $llamadas = 0;

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'env') {
                continue;
            }

            // Ni `$algo->env(...)` ni `Clase::env(...)`: esos son otra cosa.
            $anterior = $tokens[$i - 1] ?? null;

            if (is_array($anterior) && in_array($anterior[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }

            if (($tokens[$i + 1] ?? null) === '(') {
                $llamadas++;
            }
        }

        return $llamadas;
    }

    /** @return list<string> */
    private function fuentes(string $raiz): array
    {
        $archivos = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz)) as $archivo) {
            if (str_ends_with((string) $archivo, '.php')) {
                $archivos[] = (string) $archivo;
            }
        }

        return $archivos;
    }
}
