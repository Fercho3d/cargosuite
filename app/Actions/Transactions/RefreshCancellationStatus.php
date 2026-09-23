<?php

namespace App\Actions\Transactions;

use App\Models\CfdiCancelacion;
use App\Models\Core\Transaction;
use App\Support\Cfdi\CancelResult;
use App\Support\Cfdi\SatStatus;
use App\Support\Cfdi\SatStatusResult;

/**
 * Le pregunta al SAT en qué quedó una cancelación solicitada y pone al día la
 * factura.
 *
 * El PAC avisa una sola vez, cuando recibe la solicitud; lo que pase después
 * —que el receptor autorice, que la rechace o que se le venza el plazo— solo lo
 * sabe el SAT. Por eso hay que volver a preguntar, aquí desde el botón del
 * detalle y a diario desde `cfdi:revisar-cancelaciones`.
 *
 * Si el SAT no contesta no se cambia nada: un servicio caído no puede dejar la
 * factura en un estado inventado.
 */
class RefreshCancellationStatus
{
    public function __construct(private SatStatus $sat) {}

    public function handle(Transaction $transaccion): SatStatusResult
    {
        $consulta = $this->sat->consultar($transaccion);

        $solicitud = CfdiCancelacion::where('transc_id', $transaccion->transc_id)->first();

        if ($solicitud === null || ! $consulta->seConsulto()) {
            return $consulta;
        }

        $solicitud->sat_estado = $consulta->estado;
        $solicitud->sat_estatus = $consulta->estatusCancelacion ?: null;
        $solicitud->verificado_at = now();

        /*
         * Cancelado es cancelado, lo haya autorizado el receptor, lo haya dejado
         * vencer o se hubiera podido cancelar sin aceptación: es la verdad
         * fiscal y ahora sí se marca en las columnas heredadas, con el motivo y
         * el folio sustituto que se pidieron en su momento.
         */
        if ($consulta->estaCancelado()) {
            $solicitud->estado = CancelResult::CANCELADA;

            $transaccion->forceFill([
                'cancelled' => 1,
                'cancel_reason_id' => $solicitud->motivo,
                'new_seal' => $solicitud->sustituye,
            ])->save();
        }

        // El receptor dijo que no: deja de estar pendiente, y la factura sigue
        // viva. Quien factura tendrá que hablar con el cliente o sustituirla.
        if ($consulta->estatusCancelacion === 'Solicitud rechazada') {
            $solicitud->estado = CancelResult::RECHAZADA;
        }

        $solicitud->save();

        return $consulta;
    }
}
