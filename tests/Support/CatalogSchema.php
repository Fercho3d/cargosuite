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
    /**
     * @param  string  $auditoria  Tipo de `created_at`/`modified_at`: `dateTime` casi
     *                             siempre; `date` en `pickup_place`, como en la base.
     */
    public static function create(CatalogDefinition $definicion, string $auditoria = 'dateTime'): void
    {
        Schema::create($definicion->table, function ($table) use ($definicion, $auditoria) {
            $table->increments($definicion->key);

            foreach ($definicion->fields as $campo) {
                self::column($table, $campo);
            }

            // Columnas que la tabla exige y la pantalla no captura (`carrier.password`).
            foreach (array_keys($definicion->insertDefaults()) as $columna) {
                $table->string($columna);
            }

            if ($definicion->softDelete !== null) {
                $table->integer($definicion->softDelete)->default(0);
            }

            if ($definicion->audited) {
                $table->integer('created_by')->nullable();
                $table->integer('modified_by')->nullable();
                $table->{$auditoria}('created_at')->nullable();
                $table->{$auditoria}('modified_at')->nullable();
            }
        });
    }

    /** Un campo obligatorio se levanta `NOT NULL`, como en la base real. */
    private static function column(object $table, CatalogField $campo): void
    {
        $columna = match ($campo->type) {
            'boolean' => $table->boolean($campo->name)->default($campo->default ?? false),
            'number' => $table->decimal($campo->name, 12, 4),
            'date' => $table->date($campo->name),
            default => $table->string($campo->name),
        };

        if ($campo->isBoolean()) {
            return;
        }

        $campo->isRequired() ? $columna->nullable(false) : $columna->nullable();

        if ($campo->default !== null) {
            $columna->default($campo->default);
        }
    }
}
