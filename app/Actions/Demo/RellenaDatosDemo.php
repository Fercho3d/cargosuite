<?php

namespace App\Actions\Demo;

use App\Support\Ajustes;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rellena la instalación con los datos de una vertical, desde la pantalla.
 *
 * Sirve para enseñar el sistema: el cliente de camiones no ve puertos ni buques
 * ni fletes en dólares, ve **sus** rutas, sus operadores y sus tarifas por
 * kilómetro. Y como cambia también la modalidad y el vocabulario, la interfaz
 * entera se acomoda: el booking se llama viaje y el buque desaparece.
 *
 * ⚠️ **Borra y vuelve a llenar.** Por eso solo existe donde `MARCA_DEMO=true`:
 * en una instalación de un cliente el botón no está, ni la ruta responde. Un
 * interruptor que vacía la base no puede depender de que nadie le dé clic.
 */
class RellenaDatosDemo
{
    /**
     * Las verticales que se ofrecen: qué perfil de datos, qué modalidad de
     * transporte y qué vocabulario van juntos.
     *
     * @var array<string, array{perfil: string, modalidades: list<string>, vocabulario: string}>
     */
    public const VERTICALES = [
        'maritimo' => ['perfil' => 'carga', 'modalidades' => ['maritimo'], 'vocabulario' => ''],
        'terrestre' => ['perfil' => 'camiones', 'modalidades' => ['terrestre'], 'vocabulario' => 'camiones'],
    ];

    /** Solo donde se declara que la instalación es de demostración. */
    public static function permitido(): bool
    {
        return (bool) config('marca.demo');
    }

    public function __invoke(string $vertical, ?int $usuario = null): void
    {
        if (! self::permitido()) {
            throw new RuntimeException(
                'Esta instalación no es de demostración: rellenar los datos borraría los de un cliente. '.
                'Se enciende con MARCA_DEMO=true.'
            );
        }

        $elegida = self::VERTICALES[$vertical] ?? throw new RuntimeException(
            "No existe la vertical «{$vertical}»."
        );

        // Primero los ajustes: el sembrador siembra según el perfil, pero las
        // pantallas se acomodan según la modalidad, y si falla a la mitad más
        // vale que el sistema haya quedado apuntando a la vertical pedida.
        Ajustes::guardar([
            'marca.modalidades' => $elegida['modalidades'],
            'marca.vocabulario' => $elegida['vocabulario'],
        ], $usuario);

        // Sin caché de configuración por medio: el sembrador lee `config()` para
        // saber qué perfil trae, y en producción está cacheada desde el arranque.
        config(['marca.modalidades' => implode(',', $elegida['modalidades'])]);

        (new DemoSeeder)->conPerfil($elegida['perfil'])->sobrescribiendo()->run();

        DB::table('configuracion')->updateOrInsert(
            ['clave' => 'demo.vertical'],
            ['valor' => $vertical, 'modified_at' => now(), 'modified_by' => $usuario],
        );
    }

    /** Con qué vertical se llenó por última vez, si se llenó desde aquí. */
    public static function vertical(): ?string
    {
        return DB::table('configuracion')->where('clave', 'demo.vertical')->value('valor');
    }
}
