<?php

namespace App\Support\Catalogs;

use Closure;

/** Un campo de un catálogo: cómo se llama, cómo se captura y cómo se valida. */
final class CatalogField
{
    /**
     * @param  string  $type  text | number | date | boolean | select
     * @param  string[]  $rules
     * @param  ?Closure(): array<int|string, string>  $options  Solo para `select`.
     *                                                          Se resuelve al pintar y no al
     *                                                          declarar: si no, un catálogo
     *                                                          consultaría la base en cada
     *                                                          arranque, incluso sin usarse.
     * @param  mixed  $default  Valor con el que nace un registro nuevo. Si al guardar
     *                          queda vacío se omite del `INSERT` para que aplique el
     *                          default de la columna en la base.
     * @param  bool  $unique  No puede repetirse en la tabla (se ignora el propio
     *                        renglón al editar).
     * @param  ?Closure(array<string, mixed>): bool  $visibleWhen  Con qué valores del
     *                                                             formulario se enseña el campo. Oculto, no se
     *                                                             valida y se guarda `$hiddenValue`.
     * @param  mixed  $hiddenValue  Lo que se escribe cuando el campo está oculto.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly array $rules = ['nullable', 'string', 'max:255'],
        public readonly bool $inList = true,
        public readonly ?Closure $options = null,
        public readonly mixed $default = null,
        public readonly bool $unique = false,
        public readonly ?Closure $visibleWhen = null,
        public readonly mixed $hiddenValue = null,
    ) {}

    /** @param  array<string, mixed>  $form */
    public function visible(array $form): bool
    {
        return $this->visibleWhen === null || ($this->visibleWhen)($form);
    }

    /** Con qué valor arranca el campo en un alta. */
    public function initial(): mixed
    {
        return $this->default ?? ($this->isBoolean() ? false : '');
    }

    public function isRequired(): bool
    {
        return in_array('required', $this->rules, true);
    }

    /** @return array<int|string, string> */
    public function opciones(): array
    {
        return $this->options === null ? [] : ($this->options)();
    }

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
            'select' => $valor === '' || $valor === null ? null : (int) $valor,
            default => $valor === '' ? null : $valor,
        };
    }
}
