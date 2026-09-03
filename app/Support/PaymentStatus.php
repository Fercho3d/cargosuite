<?php

namespace App\Support;

/**
 * Estado de cobro/pago de una transacción, tal como lo decide
 * `Transaction::getPaidStatus()` en Yii2.
 *
 * Se compara con dos decimales porque los montos vienen de sumas de DECIMAL y el
 * original también redondea antes de decidir.
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';

    case Partial = 'partial';

    case Paid = 'paid';

    /** Sin cargos: no hay nada que cobrar y el original no muestra etiqueta. */
    case None = 'none';

    public static function for(object $row): self
    {
        $paidAmount = abs((float) ($row->tran_paid_amount ?? 0));
        $left = round((float) ($row->left_to_pay ?? 0), 2);
        $total = abs(round((float) ($row->total_natural_amount ?? 0), 2));

        if ($paidAmount === 0.0) {
            return self::Unpaid;
        }

        if ($left > 0 && abs($left) < $total) {
            return self::Partial;
        }

        return abs($left) === 0.0 ? self::Paid : self::None;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => __('Sin pagar'),
            self::Partial => __('Parcial'),
            self::Paid => __('Pagada'),
            self::None => '—',
        };
    }

    /** Clases de color para la etiqueta. */
    public function classes(): string
    {
        return match ($this) {
            self::Unpaid => 'badge badge-danger',
            self::Partial => 'badge badge-warn',
            self::Paid => 'badge badge-ok',
            self::None => 'badge badge-neutral',
        };
    }
}
