<?php

namespace App\Models;

use App\Support\Cfdi\CancelResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Solicitud de cancelación de un CFDI y lo que el SAT ha contestado sobre ella.
 *
 * Tabla propia de Laravel (`cfdi_cancelacion`); las columnas heredadas de la
 * transacción quedan intactas y el sistema viejo no se entera.
 */
class CfdiCancelacion extends Model
{
    protected $table = 'cfdi_cancelacion';

    /** Las marcas de tiempo que interesan son las del trámite, no las de la fila. */
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'solicitado_at' => 'datetime',
            'verificado_at' => 'datetime',
        ];
    }

    /** Las que siguen esperando respuesta del receptor o del SAT. */
    public function scopePendientes(Builder $consulta): Builder
    {
        return $consulta->whereIn('estado', CancelResult::PENDIENTES);
    }

    public function estaPendiente(): bool
    {
        return in_array($this->estado, CancelResult::PENDIENTES, true);
    }

    /** Horas que el SAT le da al receptor para contestar antes de cancelar por plazo vencido. */
    public const PLAZO_HORAS = 72;

    /*
     * Cómo se ve la cancelación en pantalla. Son cuatro y no tres porque hay
     * facturas que el sistema da por canceladas y el SAT sigue viendo vigentes:
     * unas porque el receptor rechazó la solicitud y otras porque la solicitud
     * nunca llegó. Decirles «cancelada» es justo lo que confundía a quien
     * factura.
     */
    public const VISTA_CANCELADA = 'cancelada';

    public const VISTA_PROCESO = 'proceso';

    public const VISTA_RECHAZADA = 'rechazada';

    public const VISTA_VIGENTE = 'vigente';

    /**
     * ¿Se le puede pedir al SAT la cancelación de esta factura?
     *
     * Mientras el SAT no la dé por cancelada, sí: una solicitud que el receptor
     * rechazó, o que se quedó en la cola del PAC, se vuelve a pedir. Antes
     * bastaba con que estuviera marcada aquí para no dejar reintentar, y las
     * once facturas que el SAT sigue viendo vigentes quedaban en un limbo del
     * que no se podía salir desde el sistema.
     */
    public static function permiteSolicitar(bool $marcadaCancelada, ?self $cancelacion): bool
    {
        if ($cancelacion === null) {
            // Sin bitácora solo queda la marca heredada: si dice cancelada, se
            // respeta hasta que el comando diario confirme con el SAT.
            return ! $marcadaCancelada;
        }

        return $cancelacion->estadoVisible() !== self::VISTA_CANCELADA;
    }

    /** @return self::VISTA_* */
    public function estadoVisible(): string
    {
        if ($this->estado === CancelResult::CANCELADA || $this->sat_estado === 'Cancelado') {
            return self::VISTA_CANCELADA;
        }

        if ($this->estado === CancelResult::RECHAZADA) {
            return self::VISTA_RECHAZADA;
        }

        // Pedida hace más del plazo y el SAT sigue sin saber de ella: no está en
        // proceso, está sin registrar.
        $vencio = $this->solicitado_at !== null
            && $this->solicitado_at->diffInHours(now()) > self::PLAZO_HORAS;

        return $this->sat_estado === 'Vigente' && $vencio ? self::VISTA_VIGENTE : self::VISTA_PROCESO;
    }
}
