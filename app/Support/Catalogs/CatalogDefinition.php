<?php

namespace App\Support\Catalogs;

/**
 * Descripción de un catálogo maestro: qué tabla es, cómo se llama en pantalla y
 * qué campos tiene.
 *
 * Los catálogos del sistema son todos la misma pantalla con otros campos, así
 * que en vez de dieciséis módulos casi iguales hay uno solo guiado por estas
 * definiciones (ver `CatalogRegistry`).
 */
final class CatalogDefinition
{
    /**
     * @param  CatalogField[]  $fields
     * @param  string|null  $softDelete  Columna de baja lógica; sin ella el borrado es real.
     * @param  string[]  $searchable  Columnas donde busca el cuadro de búsqueda.
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $table,
        public readonly string $key,
        public readonly string $singular,
        public readonly string $plural,
        public readonly array $fields,
        public readonly ?string $softDelete = null,
        public readonly ?string $orderBy = null,
        public readonly bool $audited = false,
        public readonly array $searchable = [],
        public readonly ?string $note = null,
    ) {}

    /** @return CatalogField[] */
    public function listFields(): array
    {
        return array_values(array_filter($this->fields, fn (CatalogField $f) => $f->inList));
    }

    public function field(string $name): ?CatalogField
    {
        foreach ($this->fields as $campo) {
            if ($campo->name === $name) {
                return $campo;
            }
        }

        return null;
    }

    /** @return string[] */
    public function columnNames(): array
    {
        return array_map(fn (CatalogField $f) => $f->name, $this->fields);
    }

    /** @return string[] */
    public function searchColumns(): array
    {
        return $this->searchable !== [] ? $this->searchable : [$this->fields[0]->name];
    }

    public function defaultOrder(): string
    {
        return $this->orderBy ?? $this->fields[0]->name;
    }
}
