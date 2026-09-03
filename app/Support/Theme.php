<?php

namespace App\Support;

use Illuminate\Support\Facades\Cookie;

/**
 * Tema visual de la interfaz. `System` sigue la preferencia del sistema
 * operativo y se resuelve en el navegador; las otras dos son explícitas.
 */
enum Theme: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';

    /** Nombre de la cookie que recuerda el tema de visitantes sin sesión. */
    public const COOKIE = 'app_theme';

    /**
     * Cookie que escribe el navegador con el tema ya resuelto (claro u oscuro).
     * Solo sirve cuando la preferencia es `System`: el servidor no puede saber
     * qué tiene configurado el sistema operativo, y sin este dato tendría que
     * pintar la página en claro y dejar que el script la corrigiera.
     */
    public const RESOLVED_COOKIE = 'app_theme_resolved';

    /** Duración de la cookie en minutos (un año). */
    public const COOKIE_MINUTES = 525_600;

    /**
     * Tema efectivo de la petición actual: preferencia guardada del usuario
     * autenticado y, si no hay sesión, la cookie del navegador.
     */
    public static function current(): self
    {
        $guardado = auth()->user()?->preference?->theme;

        // Sin preferencia guardada se respeta la cookie: quien eligió su tema en
        // la pantalla de acceso no debe verlo cambiar al iniciar sesión.
        return $guardado ?? self::tryFrom((string) Cookie::get(self::COOKIE)) ?? self::System;
    }

    /**
     * Tema efectivo, ya sin el caso `System`. Es lo que decide si el `<html>`
     * lleva la clase `dark` desde el servidor — necesario porque al navegar con
     * `wire:navigate` Livewire reemplaza los atributos del `<html>` con los que
     * traiga la respuesta, y una clase puesta solo por JavaScript se perdería.
     */
    public static function resolved(): self
    {
        $tema = self::current();

        if ($tema !== self::System) {
            return $tema;
        }

        return Cookie::get(self::RESOLVED_COOKIE) === self::Dark->value ? self::Dark : self::Light;
    }

    public function label(): string
    {
        return match ($this) {
            self::Light => 'Claro',
            self::Dark => 'Oscuro',
            self::System => 'Sistema',
        };
    }
}
