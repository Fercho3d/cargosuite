<?php

namespace App\Actions\Payments;

use App\Models\Core\PaymentByTransaction;
use App\Models\Core\PaymentRequest;
use App\Models\Core\Transaction;
use App\Models\User;
use App\Support\ExchangeRates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Crea una solicitud de pago a partir de un grupo de transacciones.
 *
 * Traduce `PaymentRequestController::actionCreate` y `Transaction::payTran` de
 * Yii2. Las reglas duras del original se conservan: todas las transacciones
 * tienen que compartir divisa y contraparte, porque una solicitud se paga con
 * un solo cheque a un solo tercero.
 */
class CreatePaymentRequest
{
    public function __construct(private ExchangeRates $rates) {}

    /**
     * @param  Collection<int, object>  $transacciones  Filas del motor de consulta.
     * @param  array<string, mixed>  $datos  number, date, bank_id
     * @param  array<int, float>  $importes  transc_id => importe a aplicar
     */
    public function handle(Collection $transacciones, array $datos, array $importes, User $usuario): PaymentRequest
    {
        $esCobro = (int) $transacciones->first()->tran_type === Transaction::TYPE_INVOICE;

        $this->assertSameCurrency($transacciones);
        $this->assertSameCounterparty($transacciones, $esCobro);

        $fecha = Carbon::parse($datos['date']);
        $this->rates->ensureFor($fecha);

        $primera = $transacciones->first();

        $solicitud = new PaymentRequest;
        $solicitud->forceFill([
            'number' => $datos['number'],
            'date' => $fecha->toDateString(),
            'bank_id' => (int) $datos['bank_id'],
            'currency_id' => (int) $primera->account_id,
            'type' => $esCobro ? 1 : 2,
            'client_id' => $esCobro ? $primera->customer : null,
            'provider_id' => $esCobro ? null : $primera->vendor,
            'amount' => round(array_sum($importes), 2),
            'total_to_pay' => round(array_sum($importes), 2),
            'paid' => 0,
            'opened' => 1,
            // El original guarda además un JSON con el reparto; se conserva
            // porque hay pantallas viejas que lo leen.
            'payments' => json_encode($importes),
            'created_by' => $usuario->usr_id,
            'modified_by' => $usuario->usr_id,
        ])->save();

        foreach ($transacciones as $transaccion) {
            $this->applyTo($transaccion, $solicitud->request_id, (float) ($importes[$transaccion->transc_id] ?? 0));
        }

        return $solicitud;
    }

    /**
     * Aplica el pago a una transacción: deja el renglón que la liga con la
     * solicitud y actualiza sus columnas de cobro.
     *
     * La fórmula de `paid` es la del original y **parece equivocada**
     * (`left_to_pay - paid_amount`, cuando lo natural sería comparar contra el
     * total); se conserva porque escribe en una columna que el sistema viejo
     * sigue leyendo. El estado que se enseña en pantalla no sale de ahí, sino de
     * los importes calculados, así que la rareza no se ve.
     */
    private function applyTo(object $transaccion, int $requestId, float $importe): void
    {
        PaymentByTransaction::create([
            'request_id' => $requestId,
            'transc_id' => $transaccion->transc_id,
            'amount' => $importe,
            'paid' => 1,
        ]);

        $pagadoAntes = (float) ($transaccion->paid_amount ?? 0);

        Transaction::whereKey($transaccion->transc_id)->update([
            'paid_amount' => $pagadoAntes + $importe,
            'paid' => ((float) $transaccion->left_to_pay - $pagadoAntes) === 0.0 ? 1 : 2,
            'paid_at' => now(),
        ]);
    }

    /** @param  Collection<int, object>  $transacciones */
    private function assertSameCurrency(Collection $transacciones): void
    {
        if ($transacciones->pluck('account_id')->unique()->count() > 1) {
            throw ValidationException::withMessages([
                'seleccion' => 'No se pueden agrupar transacciones de distinta divisa en la misma solicitud.',
            ]);
        }
    }

    /** @param  Collection<int, object>  $transacciones */
    private function assertSameCounterparty(Collection $transacciones, bool $esCobro): void
    {
        $columna = $esCobro ? 'customer' : 'vendor';

        if ($transacciones->pluck($columna)->unique()->count() > 1) {
            throw ValidationException::withMessages([
                'seleccion' => $esCobro
                    ? 'Todas las facturas de una solicitud tienen que ser del mismo cliente.'
                    : 'Todos los costos de una solicitud tienen que ser del mismo proveedor.',
            ]);
        }
    }
}
