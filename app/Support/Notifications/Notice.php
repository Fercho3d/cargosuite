<?php

namespace App\Support\Notifications;

/**
 * Un aviso de la bandeja: algo que está esperando a que alguien lo atienda.
 *
 * No es un mensaje guardado, es una lectura del estado de ahora. Por eso no hay
 * «leído» ni «archivado»: un aviso desaparece cuando el trabajo se hace, que es
 * la única manera honesta de quitárselo de encima.
 */
final class Notice
{
    /** Puede esperar. */
    public const AVISO = 'aviso';

    /** Hay dinero o una fecha de por medio. */
    public const URGENTE = 'urgente';

    public function __construct(
        public readonly string $grupo,
        public readonly string $titulo,
        public readonly string $detalle,
        public readonly string $ruta,
        public readonly string $nivel = self::AVISO,
        public readonly ?string $fecha = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'grupo' => $this->grupo,
            'titulo' => $this->titulo,
            'detalle' => $this->detalle,
            'ruta' => $this->ruta,
            'nivel' => $this->nivel,
            'fecha' => $this->fecha,
        ];
    }
}
