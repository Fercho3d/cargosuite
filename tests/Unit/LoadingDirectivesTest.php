<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guardia sobre los indicadores de carga de Livewire.
 *
 * Livewire oculta los elementos `wire:loading` con una hoja de estilos que
 * escribe al inicio, y esa hoja solo trae reglas para los modificadores
 * *sueltos*: `wire:loading.delay` y `wire:loading.flex` sí, `wire:loading.delay.flex`
 * no. El JavaScript tampoco los oculta al arrancar — solo cambia el display
 * cuando empieza o termina una petición. Resultado de combinarlos: el velo de
 * carga se queda pintado encima del contenido desde que carga la página.
 */
class LoadingDirectivesTest extends TestCase
{
    public function test_ningun_indicador_de_carga_combina_retardo_con_modificador_de_display(): void
    {
        $display = ['inline', 'inline-block', 'inline-flex', 'block', 'flex', 'grid', 'table', 'list-item'];
        $culpables = [];

        foreach ($this->blades() as $ruta) {
            preg_match_all('/wire:loading((?:\.[a-z-]+)+)/', file_get_contents($ruta), $coincidencias);

            foreach ($coincidencias[1] as $modificadores) {
                $partes = array_filter(explode('.', $modificadores));

                if (in_array('delay', $partes, true) && array_intersect($partes, $display)) {
                    $culpables[] = basename($ruta).': wire:loading'.$modificadores;
                }
            }
        }

        $this->assertSame([], $culpables);
    }

    /** @return string[] */
    private function blades(): array
    {
        $archivos = [];

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($iterador as $archivo) {
            if (str_ends_with($archivo->getFilename(), '.blade.php')) {
                $archivos[] = $archivo->getPathname();
            }
        }

        return $archivos;
    }
}
