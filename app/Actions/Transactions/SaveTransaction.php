<?php

namespace App\Actions\Transactions;

use App\Models\Core\Transaction;
use App\Models\User;
use App\Support\ExchangeRates;
use Illuminate\Support\Carbon;

/**
 * Alta y edición de una transacción, con las reglas que en Yii2 vivían en
 * `Transaction::beforeSave()`.
 *
 * Se saca del modelo a propósito: `beforeSave` también se dispara desde el
 * timbrado, los pagos y las cancelaciones, y ahí no se quiere ni renumerar ni
 * pedirle el tipo de cambio al DOF.
 */
class SaveTransaction
{
    public function __construct(private ExchangeRates $rates) {}

    /** @param  array<string, mixed>  $datos */
    public function handle(Transaction $transaccion, array $datos, User $usuario): Transaction
    {
        $esNueva = ! $transaccion->exists;

        $transaccion->fill($datos);
        $transaccion->tran_date = Carbon::parse($datos['tran_date'])->toDateString();

        if ($esNueva) {
            // El original abre toda transacción nueva; el cierre lo hacen después
            // el timbrado o el pago.
            $transaccion->open = 1;
            $transaccion->created_by = $usuario->usr_id;

            $this->assignInvoiceNumber($transaccion);
        }

        $transaccion->modified_by = $usuario->usr_id;

        // El tipo de cambio del día se registra antes de guardar, para que la
        // transacción ya tenga con qué valuarse. Si el DOF no responde, se sigue.
        $this->rates->ensureFor(Carbon::parse($transaccion->tran_date));

        $transaccion->save();

        return $transaccion;
    }

    /**
     * Folio consecutivo `F-n`, solo para facturas de un booking real y de tipo
     * normal o nota de crédito. Las históricas (`invoice_type` 2) llevan el
     * número que se captura a mano.
     *
     * Hereda del original una carrera conocida: dos altas simultáneas pueden leer
     * el mismo máximo. En producción las tablas son MyISAM (sin transacciones),
     * así que la solución no es un `SELECT ... FOR UPDATE`; se deja igual que en
     * Yii2 y se resuelve, si aparece, con una secuencia propia.
     */
    private function assignInvoiceNumber(Transaction $transaccion): void
    {
        $tipos = [Transaction::INVOICE_TYPE_NORMAL, Transaction::INVOICE_TYPE_CREDIT];

        $aplica = (int) $transaccion->tran_type === Transaction::TYPE_INVOICE
            && in_array((int) $transaccion->invoice_type, $tipos, true)
            && ! ($transaccion->bookingModel?->isQuotation() ?? false);

        if (! $aplica) {
            return;
        }

        $transaccion->invoice = (int) Transaction::max('invoice') + 1;
        $transaccion->tran_number = 'F-'.$transaccion->invoice;
    }
}
