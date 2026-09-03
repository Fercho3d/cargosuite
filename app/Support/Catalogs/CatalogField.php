<?php

namespace App\Support\Catalogs;

/** Un campo de un catálogo: cómo se llama, cómo se captura y cómo se valida. */
final class CatalogField
{
    /**
     * @param  string  $type  text | number | date | boolean
     * @param  string[]  $rules
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly array $rules = ['nullable', 'string', 'max:255'],
        public readonly bool $inList = true,
    ) {}

    public function isBoolean(): bool
    {
        return $this->type === 'boolean';
    }

    /** Valor listo para guardar en la base. */
    public function cast(mixed $valor): mixed
    {
        return match ($this->type) {
            'boolean' => $valor ? 1 : 0,
            'number' => $valor === '' || $valor === null ? null : (float) $valor,
            default => $valor === '' ? null : $valor,
        };
    }
}
