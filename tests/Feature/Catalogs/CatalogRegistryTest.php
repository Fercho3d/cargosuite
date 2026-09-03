<?php

namespace Tests\Feature\Catalogs;

use App\Support\Catalogs\CatalogDefinition;
use App\Support\Catalogs\CatalogRegistry;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\LegacyDatabaseTestCase;

/**
 * Que el registro de catálogos describa la base de verdad.
 *
 * Los catálogos no tienen modelo ni migración: son definiciones escritas a mano
 * contra un esquema heredado con nombres poco predecibles (`contType_id`,
 * `dicharge_port`, `pay_terms`…). Una letra de más en cualquiera de ellos
 * revienta la pantalla en tiempo de ejecución, así que se comprueba contra el
 * esquema real. Es una prueba de solo lectura.
 */
#[Group('parity')]
class CatalogRegistryTest extends LegacyDatabaseTestCase
{
    public function test_cada_catalogo_apunta_a_una_tabla_que_existe(): void
    {
        foreach (CatalogRegistry::all() as $slug => $definicion) {
            $this->assertTrue(
                Schema::connection('frego_legacy')->hasTable($definicion->table),
                "El catálogo «{$slug}» apunta a la tabla inexistente «{$definicion->table}»."
            );
        }
    }

    public function test_cada_columna_declarada_existe_en_su_tabla(): void
    {
        foreach (CatalogRegistry::all() as $slug => $definicion) {
            $columnas = array_merge(
                [$definicion->key],
                $definicion->columnNames(),
                $definicion->softDelete !== null ? [$definicion->softDelete] : [],
                $definicion->audited ? ['created_by', 'created_at', 'modified_by', 'modified_at'] : [],
            );

            foreach ($columnas as $columna) {
                $this->assertTrue(
                    Schema::connection('frego_legacy')->hasColumn($definicion->table, $columna),
                    "El catálogo «{$slug}» declara la columna «{$columna}», que no existe en «{$definicion->table}»."
                );
            }
        }
    }

    public function test_las_columnas_de_busqueda_y_orden_tambien_existen(): void
    {
        foreach (CatalogRegistry::all() as $slug => $definicion) {
            foreach ([...$definicion->searchColumns(), $definicion->defaultOrder()] as $columna) {
                $this->assertTrue(
                    Schema::connection('frego_legacy')->hasColumn($definicion->table, $columna),
                    "El catálogo «{$slug}» ordena o busca por «{$columna}», que no existe."
                );
            }
        }
    }

    public function test_cada_catalogo_tiene_al_menos_un_campo(): void
    {
        foreach (CatalogRegistry::all() as $slug => $definicion) {
            $this->assertNotEmpty($definicion->fields, "El catálogo «{$slug}» no declara campos.");
            $this->assertInstanceOf(CatalogDefinition::class, $definicion);
        }
    }
}
