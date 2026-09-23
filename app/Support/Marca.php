<?php

namespace App\Support;

/**
 * Lee `config/marca.php` y resuelve lo poco que necesita una decisión: qué
 * logotipo toca según el tema, qué dirección tiene el portal y qué variables
 * CSS hay que pintar para que el color de acento sea el del cliente.
 *
 * Existe para que las vistas no lleven `??` ni comprobaciones de rutas, y para
 * poder probar las reglas sin pintar una pantalla.
 */
class Marca
{
    /** Nombre del producto tal como se enseña al usuario. */
    public static function nombre(): string
    {
        return (string) config('marca.nombre');
    }

    /** Etiqueta corta junto al logotipo, o cadena vacía si no se quiere. */
    public static function etiqueta(): string
    {
        return trim((string) config('marca.etiqueta'));
    }

    public static function lema(): string
    {
        return trim((string) config('marca.lema'));
    }

    public static function pie(): string
    {
        return trim((string) config('marca.pie'));
    }

    /**
     * ¿El logotipo es una imagen? Basta con que esté configurada la versión
     * clara: la oscura es opcional y cae en la clara.
     */
    public static function usaImagen(): bool
    {
        return trim((string) config('marca.logo.imagen.claro')) !== '';
    }

    /**
     * Dirección del logotipo para un tema. `oscuro` cae en la imagen clara
     * cuando no hay una versión propia, que es el caso de un logotipo que ya
     * se lee bien sobre los dos fondos.
     */
    public static function logo(string $tema = 'claro'): ?string
    {
        $claro = trim((string) config('marca.logo.imagen.claro'));

        if ($claro === '') {
            return null;
        }

        $elegida = $tema === 'oscuro'
            ? (trim((string) config('marca.logo.imagen.oscuro')) ?: $claro)
            : $claro;

        return self::url($elegida);
    }

    /** ¿Hay una imagen oscura distinta de la clara? */
    public static function tieneLogoOscuro(): bool
    {
        $oscuro = trim((string) config('marca.logo.imagen.oscuro'));

        return $oscuro !== '' && $oscuro !== trim((string) config('marca.logo.imagen.claro'));
    }

    public static function favicon(): ?string
    {
        $favicon = trim((string) config('marca.favicon'));

        return $favicon === '' ? null : self::url($favicon);
    }

    /**
     * Dirección del portal de clientes y proveedores. Sale en los correos, así
     * que tiene que ser absoluta; sin configurar se arma con `APP_URL`.
     */
    public static function portal(): string
    {
        $portal = trim((string) config('marca.empresa.portal'));

        return $portal !== '' ? $portal : rtrim((string) config('app.url'), '/').'/portal';
    }

    public static function empresa(): string
    {
        return trim((string) config('marca.empresa.nombre')) ?: self::nombre();
    }

    /**
     * Variables CSS del color de acento, para pintarlas en el <head>.
     *
     * Se hace en caliente y no al compilar porque las utilidades de Tailwind v4
     * emiten `var(--color-accent-500)`: basta con volver a declarar la variable
     * después de la hoja de estilos para que TODA la interfaz cambie de color
     * sin `npm run build`. `--brand` es el tono del acento sobre texto, que
     * cambia entre temas (más oscuro en claro, más claro en oscuro) y por eso
     * se declara aparte en `:root` y en `.dark`.
     */
    public static function estilos(): string
    {
        $c = config('marca.colores');

        $raiz = [
            '--color-accent-400' => $c['acento_400'] ?? null,
            '--color-accent-500' => $c['acento_500'] ?? null,
            '--color-accent-600' => $c['acento_600'] ?? null,
            '--color-accent-700' => $c['acento_700'] ?? null,
            '--brand' => $c['marca_claro'] ?? null,
            '--livewire-progress-bar-color' => $c['acento_500'] ?? null,
        ];

        $css = ':root{'.self::declaraciones($raiz).'}';
        $css .= '.dark{'.self::declaraciones(['--brand' => $c['marca_oscuro'] ?? null]).'}';

        return $css;
    }

    /**
     * Los seis tonos que usa la interfaz, sacados de un solo color.
     *
     * Quien personaliza desde Ajustes elige UN color; pedirle cuatro tonos y
     * dos variantes de texto es pedirle que sepa de diseño. El elegido es el
     * 500 y los demás se mueven en luminosidad conservando el tono, que es
     * como está armada la escala de Tailwind.
     *
     * @return array{acento_400: string, acento_500: string, acento_600: string, acento_700: string, marca_claro: string, marca_oscuro: string}
     */
    public static function paleta(string $hex): array
    {
        [$r, $g, $b] = array_map(fn (string $par) => hexdec($par) / 255, str_split(ltrim($hex, '#'), 2));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        $s = $d == 0 ? 0 : $d / (1 - abs(2 * $l - 1));
        $h = match (true) {
            $d == 0 => 0,
            $max == $r => 60 * fmod(($g - $b) / $d, 6),
            $max == $g => 60 * (($b - $r) / $d + 2),
            default => 60 * (($r - $g) / $d + 4),
        };

        $tono = fn (float $delta) => self::hsl($h, $s, max(0.05, min(0.9, $l + $delta)));

        return [
            'acento_400' => $tono(0.1),
            'acento_500' => strtolower($hex),
            'acento_600' => $tono(-0.07),
            'acento_700' => $tono(-0.14),
            'marca_claro' => $tono(-0.14),
            'marca_oscuro' => $tono(0.1),
        ];
    }

    private static function hsl(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match ((int) floor(fmod($h + 360, 360) / 60)) {
            0 => [$c, $x, 0], 1 => [$x, $c, 0], 2 => [0, $c, $x],
            3 => [0, $x, $c], 4 => [$x, 0, $c], default => [$c, 0, $x],
        };

        return sprintf('#%02x%02x%02x', round(($r + $m) * 255), round(($g + $m) * 255), round(($b + $m) * 255));
    }

    /**
     * Solo se aceptan colores con forma de color CSS seguro. El valor viene del
     * `.env`, o sea de fuera del código, y termina dentro de una etiqueta
     * <style>: sin este filtro bastaría un valor con `}` para escribir CSS
     * arbitrario en todas las pantallas.
     */
    private static function declaraciones(array $variables): string
    {
        $salida = '';

        foreach ($variables as $nombre => $valor) {
            $valor = trim((string) $valor);

            if ($valor !== '' && preg_match('/^(#[0-9a-fA-F]{3,8}|(rgb|hsl)a?\([0-9a-zA-Z%.,\/\s]+\))$/', $valor)) {
                $salida .= $nombre.':'.$valor.';';
            }
        }

        return $salida;
    }

    /** Deja pasar las direcciones completas y resuelve las rutas de `public/`. */
    private static function url(string $ruta): string
    {
        return str_starts_with($ruta, 'http://') || str_starts_with($ruta, 'https://') || str_starts_with($ruta, '//')
            ? $ruta
            : asset(ltrim($ruta, '/'));
    }
}
