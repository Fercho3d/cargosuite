<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\App;

/**
 * El idioma de los documentos que salen de la empresa.
 *
 * Los PDF y los correos del sistema estaban **en inglés fijo** porque replican
 * los del sistema anterior, y no seguían el idioma de la interfaz: un usuario
 * trabajando en español mandaba una confirmación en inglés y no había manera de
 * cambiarlo sin tocar las plantillas.
 *
 * Ahora el idioma lo pone la instalación (`MARCA_IDIOMA_DOCUMENTOS`) y es
 * **independiente del idioma de quien está usando el sistema**, que es lo
 * correcto: el documento lo lee el cliente, no el operador.
 *
 * ⚠️ Por omisión sigue siendo `en`, para que la instalación original siga
 * emitiendo exactamente lo mismo que emitía. Las pruebas de paridad comparan
 * esos documentos carácter por carácter.
 */
class Documentos
{
    /**
     * Corre algo con el idioma de los documentos y deja el de antes al terminar.
     *
     * El `finally` no es adorno: si la plantilla revienta a medias y no se
     * restaura, la petición sigue con el idioma equivocado y el usuario ve el
     * resto de la pantalla en otro idioma sin entender por qué.
     *
     * @template T
     *
     * @param  Closure(): T  $accion
     * @return T
     */
    public static function conIdioma(Closure $accion): mixed
    {
        $anterior = App::getLocale();
        $documentos = (string) config('marca.idioma_documentos');

        if ($documentos === '' || $documentos === $anterior) {
            return $accion();
        }

        App::setLocale($documentos);

        try {
            return $accion();
        } finally {
            App::setLocale($anterior);
        }
    }
}
