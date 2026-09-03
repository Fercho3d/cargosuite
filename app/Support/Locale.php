<?php

namespace App\Support;

use Illuminate\Support\Facades\Cookie;

/**
 * Idioma de la interfaz.
 *
 * El sistema se escribió en español, así que el español es la lengua base: las
 * llaves de traducción **son** el texto en español y `lang/en.json` las traduce.
 * Lo que todavía no esté traducido se ve en español, que es un respaldo honesto:
 * nunca sale una llave cruda ni un hueco.
 *
 * Ojo con el vocabulario: los términos del comercio marítimo (booking, shipper,
 * gate in) se quedan en inglés en los dos idiomas, porque así los dice la gente
 * de operación.
 */
enum Locale: string
{
    case Es = 'es';

    case En = 'en';

    /** Cookie que recuerda el idioma de quien todavía no inicia sesión. */
    public const COOKIE = 'app_locale';

    /** Un año, igual que la del tema. */
    public const COOKIE_MINUTES = 525_600;

    /**
     * Idioma efectivo: la preferencia guardada del usuario y, sin sesión, la
     * cookie del navegador. Sin ninguna de las dos, español.
     */
    public static function current(): self
    {
        $guardado = auth()->user()?->preference?->locale;

        return $guardado ?? self::tryFrom((string) Cookie::get(self::COOKIE)) ?? self::Es;
    }

    public function label(): string
    {
        return match ($this) {
            self::Es => 'Español',
            self::En => 'English',
        };
    }

    /** Las dos letras que se pintan en el botón. */
    public function short(): string
    {
        return strtoupper($this->value);
    }
}
