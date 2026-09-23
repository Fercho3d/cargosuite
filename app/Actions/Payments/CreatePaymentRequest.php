<?php

namespace App\Actions\Payments;

use App\Models\Core\Exchange;
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
    public function __construct(private ExchangeRates $rates, private RecalculatePaidColumns $recalc) {}

    /**
     * @param  Collection<int, object>  $transacciones  Filas del motor de consulta.
     * @param  array<string, mixed>  $datos  number, date, bank_id y, opcionales, custom_tc y tc_value
     * @param  array<int, float>  $importes  transc_id => importe a aplicar
     */
    public function handle(Collection $transacciones, array $datos, array $importes, User $usuario): PaymentRequest
    {
        $esCobro = (int) $transacciones->first()->tran_type === Transaction::TYPE_INVOICE;

        $this->assertSameCurrency($transacciones);
        $this->assertSameCounterparty($transacciones, $esCobro);
        $this->assertPayable($transacciones);

        $fecha = Carbon::parse($datos['date']);
        $this->rates->ensureFor($fecha);

        $primera = $transacciones->first();
        $tcPropio = (bool) ($datos['custom_tc'] ?? false);

        $solicitud = new PaymentRequest;
        $solicitud->forceFill([
            'number' => $datos['number'],
            'date' => $fecha->toDateString(),
            'bank_id' => (int) $datos['bank_id'],
            'currency_id' => (int) $primera->account_id,
            // Con «tipo de cambio propio» se guarda el capturado y el motor lo
            // usa en vez del del día (`custom_tc = 1`). Si no, se anota el del
            // día como referencia, igual que el original.
            'custom_tc' => $tcPropio ? 1 : 0,
            'tc_value' => $tcPropio ? round((float) $datos['tc_value'], 4) : $this->dayRate($fecha, (int) $primera->account_id),
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
     * El tipo de cambio registrado para la fecha; el de la divisa de la
     * solicitud si lo hay y, si no, el del día (el original tomaba el primero
     * registrado para esa fecha sin mirar la cuenta).
     */
    private function dayRate(Carbon $fecha, int $currencyId): ?float
    {
        $valor = Exchange::whereDate('date_exchange', $fecha)
            ->orderByRaw('CASE WHEN account = ? THEN 0 ELSE 1 END', [$currencyId])
            ->orderBy('exchange_id')
            ->value('exchange_value');

        return $valor === null ? null : round((float) $valor, 4);
    }

    /**
     * Por qué una transacción no puede entrar en una solicitud nueva, o null si
     * sí puede.
     *
     * En Yii2 estos documentos ni siquiera tenían casilla en la rejilla
     * (`_transactions.php` la apagaba con `left_to_pay == 0 && amount_original
     * != 0`) y las canceladas no salían en el listado. Aquí llegan por la
     * dirección (`?ids=`), así que se rechazan con un motivo claro. Lo usan el
     * alta y `handle()`; la solicitud reabierta no lo aplica porque ahí un
     * renglón saldado lo está por esa misma solicitud.
     */
    public static function rejectionReason(object $transaccion): ?string
    {
        if ((int) $transaccion->cancelled === 1) {
            return __('La transacción está cancelada: no se puede pedir su pago.');
        }

        if (round((float) $transaccion->left_to_pay, 2) === 0.0 && round((float) $transaccion->amount_original, 2) !== 0.0) {
            return __('La transacción ya está saldada: no se puede volver a pedir su pago.');
        }

        return null;
    }

    /** @param  Collection<int, object>  $transacciones */
    private function assertPayable(Collection $transacciones): void
    {
        foreach ($transacciones as $transaccion) {
            if ($motivo = self::rejectionReason($transaccion)) {
                throw ValidationException::withMessages(['seleccion' => ($transaccion->tran_number ?: $transaccion->transc_id).': '.$motivo]);
            }
        }
    }

    /**
     * Aplica el pago a una transacción: deja el renglón que la liga con la
     * solicitud y recalcula sus columnas heredadas de cobro. También lo usa la
     * solicitud reabierta al agregarle una transacción (`PaymentRequestDetail`).
     */
    public function applyTo(object $transaccion, int $requestId, float $importe): void
    {
        PaymentByTransaction::create([
            'request_id' => $requestId,
            'transc_id' => $transaccion->transc_id,
            'amount' => $importe,
            'paid' => 1,
        ]);

        $this->recalc->handle([(int) $transaccion->transc_id]);
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
