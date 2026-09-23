<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Los ajustes de la instalación que se pueden cambiar desde el sistema.
 *
 * Antes vivían solo en el `.env`, o sea que cambiar «esta empresa es terrestre»
 * exigía entrar por SSH. Aquí la tabla manda y el `.env` queda como el valor de
 * arranque, que es lo que espera cualquiera que abra una pantalla de ajustes.
 *
 * La lista es corta y cerrada a propósito: son los interruptores que cambian
 * **cómo se comporta** la instalación, no un editor de `config/` por la puerta
 * de atrás.
 */
class Ajustes
{
    /**
     * Qué se puede cambiar y cómo se valida. La clave es la de `config()`.
     *
     * @var array<string, array{tipo: string, opciones?: list<string>}>
     */
    public const PERMITIDOS = [
        'marca.modalidades' => ['tipo' => 'lista', 'opciones' => ['maritimo', 'terrestre']],
        'marca.nomina' => ['tipo' => 'booleano'],
        'marca.taller' => ['tipo' => 'booleano'],
        'timbrado.habilitado' => ['tipo' => 'booleano'],
        'marca.vocabulario' => ['tipo' => 'texto'],
        'marca.idioma_documentos' => ['tipo' => 'opcion', 'opciones' => ['es', 'en']],
    ];

    /**
     * Vuelca lo guardado sobre la configuración de la petición.
     *
     * Va en el arranque y **antes** de que nadie lea `config('marca.*')`. Es
     * defensivo a propósito: en una instalación recién clonada la tabla todavía
     * no existe, y un ajuste no puede impedir que el sistema levante.
     */
    public static function aplicar(): void
    {
        foreach (self::guardados() as $clave => $valor) {
            if (array_key_exists($clave, self::PERMITIDOS)) {
                config([$clave => $valor]);
            }
        }
    }

    /** @return array<string, mixed> */
    public static function guardados(): array
    {
        try {
            if (! Schema::hasTable('configuracion')) {
                return [];
            }

            return DB::table('configuracion')->pluck('valor', 'clave')
                ->map(fn (?string $valor, string $clave) => self::desdeTexto($clave, $valor))
                ->all();
        } catch (Throwable) {
            // Sin base todavía —instalando, o migrando— el sistema arranca con
            // lo que diga el `.env`.
            return [];
        }
    }

    /** @param  array<string, mixed>  $valores */
    public static function guardar(array $valores, ?int $usuario = null): void
    {
        foreach ($valores as $clave => $valor) {
            if (! array_key_exists($clave, self::PERMITIDOS)) {
                continue;
            }

            DB::table('configuracion')->updateOrInsert(
                ['clave' => $clave],
                ['valor' => self::aTexto($clave, $valor), 'modified_at' => now(), 'modified_by' => $usuario],
            );

            config([$clave => $valor]);
        }

        Expediente::olvida();
    }

    private static function aTexto(string $clave, mixed $valor): ?string
    {
        return match (self::PERMITIDOS[$clave]['tipo']) {
            'lista' => implode(',', array_values(array_filter((array) $valor))),
            'booleano' => $valor ? '1' : '0',
            default => $valor === null ? null : (string) $valor,
        };
    }

    private static function desdeTexto(string $clave, ?string $valor): mixed
    {
        if (! array_key_exists($clave, self::PERMITIDOS)) {
            return $valor;
        }

        return match (self::PERMITIDOS[$clave]['tipo']) {
            'booleano' => $valor === '1',
            default => (string) $valor,
        };
    }
}
