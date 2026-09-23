<?php

/*
 * Genera una copia del proyecto lista para desplegar con el código PHP
 * minificado: sin comentarios ni espacios sobrantes.
 *
 * Es protección de COPIA, no un candado: sirve para que quien reciba el paquete
 * en su servidor no lea con comodidad el código ni los comentarios. No cambia el
 * comportamiento del programa.
 *
 * Deliberadamente CONSERVADOR: usa `php_strip_whitespace()`, que solo quita
 * comentarios y espacios (a nivel de tokens) y NO renombra clases, métodos ni
 * propiedades. Esto es imprescindible aquí: Livewire manda por la red los
 * nombres de sus componentes y de sus propiedades públicas, así que renombrarlos
 * rompería la aplicación. Con esto, los nombres quedan intactos y nada se rompe.
 *
 * Las plantillas Blade no son PHP válido por sí solas, así que a ellas NO se les
 * aplica el tokenizador: solo se les quitan los comentarios `{{-- --}}`.
 *
 * Uso:
 *   php tools/build-release.php <carpeta_destino>
 */

$root = dirname(__DIR__);
$dest = $argv[1] ?? null;

if ($dest === null) {
    fwrite(STDERR, "Uso: php tools/build-release.php <carpeta_destino>\n");
    exit(1);
}

// Nunca construir dentro del propio proyecto.
$dest = rtrim($dest, '/');
if (str_starts_with($dest.'/', $root.'/')) {
    fwrite(STDERR, "El destino no puede estar dentro del proyecto.\n");
    exit(1);
}

// Lo que no viaja: control de versiones, dependencias que se instalan aparte,
// pruebas, y todo lo escribible/local del entorno.
$excluded = [
    '.git', 'node_modules', 'vendor', 'tests', 'storage', 'build',
    '.env', '.env.example', '.phpunit.result.cache', '.phpunit.cache',
];

// Rutas anidadas que NO deben viajar: la caché compilada de arranque es de la
// máquina donde se generó (lista los paquetes de desarrollo, p. ej. Pail) y el
// despliegue la regenera en el servidor. Si se copia, tumba el sitio en producción.
$excludedPaths = [
    'bootstrap/cache',
];

$isExcluded = static function (string $relative) use ($excluded, $excludedPaths): bool {
    if (in_array(explode('/', $relative)[0], $excluded, true)) {
        return true;
    }

    foreach ($excludedPaths as $ruta) {
        if ($relative === $ruta || str_starts_with($relative, $ruta.'/')) {
            return true;
        }
    }

    return false;
};

if (! is_dir($dest) && ! mkdir($dest, 0755, true) && ! is_dir($dest)) {
    fwrite(STDERR, "No se pudo crear el destino: {$dest}\n");
    exit(1);
}

// Se PODAN los directorios excluidos antes de descender: si no, el iterador
// intenta entrar a `storage` (p. ej. `storage/app/private/livewire-tmp`, sin
// permiso de lectura) y truena a media construcción, dejando el paquete
// incompleto —fue justo lo que pasó con `lang/en.json`—.
$podados = new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    static function ($current) use ($isExcluded, $root): bool {
        return ! $isExcluded(substr($current->getPathname(), strlen($root) + 1));
    },
);

$files = new RecursiveIteratorIterator($podados, RecursiveIteratorIterator::SELF_FIRST);

$copied = 0;
$stripped = 0;
$blades = 0;

foreach ($files as $file) {
    $relative = substr($file->getPathname(), strlen($root) + 1);

    if ($isExcluded($relative)) {
        continue;
    }

    $target = $dest.'/'.$relative;

    if ($file->isDir()) {
        is_dir($target) || mkdir($target, 0755, true);

        continue;
    }

    $name = $file->getFilename();

    if (str_ends_with($name, '.blade.php')) {
        // Solo se quitan los comentarios de Blade; el resto queda igual.
        $contents = (string) file_get_contents($file->getPathname());
        file_put_contents($target, preg_replace('/\{\{--.*?--\}\}/s', '', $contents));
        $blades++;
    } elseif (str_ends_with($name, '.php')) {
        // Minificado real por tokens: quita comentarios y espacios, conserva
        // los nombres (Livewire depende de ellos).
        file_put_contents($target, php_strip_whitespace($file->getPathname()));
        $stripped++;
    } else {
        copy($file->getPathname(), $target);
        $copied++;
    }
}

echo "Construido en: {$dest}\n";
echo "PHP minificado: {$stripped}\n";
echo "Blade sin comentarios: {$blades}\n";
echo "Otros archivos copiados: {$copied}\n";
echo "\nFalta en el destino (se instalan/generan aparte): vendor, .env, storage, public/build.\n";
