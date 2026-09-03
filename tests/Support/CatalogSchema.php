<?php

namespace Tests\Support;

use App\Support\Catalogs\CatalogDefinition;
use App\Support\Catalogs\CatalogField;
use Illuminate\Support\Facades\Schema;

/**
 * Levanta en la conexión de pruebas la tabla que describe un catálogo.
 *
 * Se construye a partir de la propia definición: así la prueba ejercita la misma
 * descripción que usa la pantalla, y no una copia que puede quedar desfasada.
 */
class CatalogSchema
{
    public static function create(CatalogDefinition $definicion): void
    {
        Schema::create($definicion->table, function ($table) use ($definicion) {
            $table->increments($definicion->key);

            foreach ($definicion->fields as $campo) {
                self::column($table, $campo);
            }

            if ($definicion->softDelete !== null) {
                $table->integer($definicion->softDelete)->default(0);
            }

            if ($definicion->audited) {
                $table->integer('created_by')->nullable();
                $table->integer('modified_by')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('modified_at')->nullable();
            }
        });
    }

    private static function column(object $table, CatalogField $campo): void
    {
        match ($campo->type) {
            'boolean' => $table->boolean($campo->name)->default(false),
            'number' => $table->decimal($campo->name, 12, 4)->nullable(),
            'date' => $table->date($campo->name)->nullable(),
            default => $table->string($campo->name)->nullable(),
        };
    }
}
