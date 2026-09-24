<?php

namespace App\Support\Gps;

/**
 * Marcas de GPS que se ven en camiones en México y el protocolo que hablan.
 *
 * No hay un estándar: cada fabricante tiene el suyo. Por eso los equipos no le
 * hablan a CargoSuite sino a un servidor Traccar, que entiende más de 200
 * protocolos y reenvía la posición ya traducida. Cada protocolo escucha en su
 * propio puerto de Traccar (los de fábrica van aquí), y así una flota puede
 * mezclar marcas sin problema.
 *
 * La app del celular (Traccar Client, protocolo OsmAnd) puede ir directo a la
 * API de CargoSuite, sin Traccar de por medio.
 */
class Protocolos
{
    /** @var array<string, array{nombre: string, puerto: ?int}> */
    public const TODOS = [
        'teltonika' => ['nombre' => 'Teltonika (FMB/FMC)', 'puerto' => 5027],
        'queclink' => ['nombre' => 'Queclink (GV300, GV350)', 'puerto' => 5004],
        'gt06' => ['nombre' => 'Concox / Jimi (GT06)', 'puerto' => 5023],
        'suntech' => ['nombre' => 'Suntech', 'puerto' => 5011],
        'ruptela' => ['nombre' => 'Ruptela', 'puerto' => 5046],
        'meitrack' => ['nombre' => 'Meitrack', 'puerto' => 5020],
        'osmand' => ['nombre' => 'App del celular (Traccar Client)', 'puerto' => null],
        'otro' => ['nombre' => 'Otro (ver tabla de Traccar)', 'puerto' => null],
    ];

    /** @return array<string, string> */
    public static function opciones(): array
    {
        return array_map(fn (array $p) => $p['nombre'], self::TODOS);
    }
}
