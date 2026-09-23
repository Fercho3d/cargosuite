<?php

namespace App\Actions\Payments;

use App\Models\Core\PaymentByTransaction;
use App\Models\Core\Transaction;
use App\Queries\TransactionFilters;
use App\Queries\TransactionQuery;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula las columnas heredadas de cobro de `transaction` (`paid_amount`,
 * `paid` y `paid_at`) a partir de `payments_by_transaction`.
 *
 * Las pantallas nuevas no las leen (el saldo sale de los renglones de pago),
 * pero el Yii2 que sigue en operación sí, y cada acción sobre una solicitud las
 * dejaba a su manera: `payTran` acumulaba al crear, `payRequest` volvía a
 * acumular al pagar (duplicando), `unpay` escribía el total y corregir o quitar
 * renglones no las tocaba. Aquí hay una sola regla y todas las acciones (crear,
 * pagar, reabrir, corregir, quitar, agregar y borrar) la aplican:
 *
 *  - `paid_amount` es la suma de lo aplicado por TODAS las solicitudes que
 *    existen sobre la transacción, pendientes o pagadas: en el original crear
 *    la solicitud ya contaba, y así coincide con el `left_to_pay` de las
 *    pantallas nuevas.
 *  - `paid` es 0 sin renglones, 1 cuando el documento queda saldado y 2 cuando
 *    es parcial (los tres valores del original).
 *  - `paid_at` se fija al pasar de 0 a 1 o 2, se conserva mientras siga ahí y
 *    se limpia al volver a 0.
 */
class RecalculatePaidColumns
{
    /** Las transacciones de una solicitud, estén o no ya pagadas por otras. */
    public function forRequest(int $requestId): void
    {
        $this->handle(PaymentByTransaction::where('request_id', $requestId)->pluck('transc_id')->all());
    }

    /** @param  int[]  $transcIds */
    public function handle(array $transcIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $transcIds)));

        if ($ids === []) {
            return;
        }

        $renglones = DB::table('payments_by_transaction')
            ->whereIn('transc_id', $ids)
            ->groupBy('transc_id')
            ->selectRaw('transc_id, SUM(IFNULL(amount, 0)) AS pagado')
            ->get()
            ->keyBy('transc_id');

        $actuales = Transaction::whereKey($ids)->get(['transc_id', 'paid', 'paid_at'])->keyBy('transc_id');

        // El total del documento en su divisa, canceladas incluidas: una
        // cancelada con pagos viejos también tiene que quedar bien contada.
        $filtros = TransactionFilters::make(['tran_in' => $ids]);
        $filtros->paymentMode = true;
        $filtros->noExchange = true;
        $filtros->showCancelled = 1;

        foreach (TransactionQuery::make($filtros)->get() as $transaccion) {
            $renglon = $renglones[$transaccion->transc_id] ?? null;
            $importe = round((float) ($renglon->pagado ?? 0), 2);
            $saldo = round(abs((float) $transaccion->total_natural_amount) - abs($importe), 2);
            $estado = match (true) {
                $renglon === null => 0,
                $saldo <= 0 => 1,
                default => 2,
            };
            $actual = $actuales[$transaccion->transc_id] ?? null;

            Transaction::whereKey($transaccion->transc_id)->update([
                'paid_amount' => $importe,
                'paid' => $estado,
                'paid_at' => match (true) {
                    $estado === 0 => null,
                    (int) $actual?->paid !== 0 && $actual?->paid_at !== null => $actual->paid_at,
                    default => now(),
                },
            ]);
        }
    }
}
