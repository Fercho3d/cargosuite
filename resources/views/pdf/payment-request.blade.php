{{--
    La solicitud de pago impresa: un cheque con el importe en letra y, debajo,
    las facturas que cubre. Copia de `PaymentRequest::generateDocument()` de
    Yii2, con los mismos textos en inglés.
--}}
<div class="paycheck">
    <div class="company-name">{{ \App\Support\Marca::empresa() }}</div>
    <div class="number">{{ $solicitud->number }}</div>
    <div class="address">
        @include('pdf.membrete')
    </div>
    <div class="date">{{ $fecha }}</div>
    <div class="pay">
        <div class="label">{{ __('impresos.pay') }}</div>
        <div class="vendor-name">{{ $beneficiario }}</div>
        <div class="amount">$ {{ $importe }}</div>
    </div>
    <div class="amount-text">
        <span>{{ $importeEnLetra }}</span>
        <span>{{ $centavos }}/100{{ $divisa }}</span>
    </div>
    <div class="to-the"><span>{{ __('impresos.to_the_order') }}</span> {{ $beneficiario }}</div>
    <div class="memo">
        <div class="label">{{ __('impresos.memo') }}</div>
        <div class="col-1">&nbsp;</div>
        <div class="col-2">&nbsp;</div>
    </div>
    <div class="last-number">{{ $cuentaBancaria }}</div>
</div>

<table class="bills">
    <tr>
        <th>{{ __('impresos.number') }}</th>
        <th>Booking</th>
        <th>{{ __('impresos.amount') }}</th>
        <th>Subtotal %0</th>
        <th>Subtotal %16</th>
        <th>VAT 16%</th>
        <th>{{ __('impresos.ret_vat') }}</th>
        <th>{{ __('impresos.non_deductible') }}</th>
        <th>{{ __('impresos.amount') }}</th>
    </tr>
    @foreach ($transacciones as $transaccion)
        <tr>
            <td>{{ $transaccion->tran_number }}</td>
            <td>{{ $transaccion->booking_number }}</td>
            <td style="text-align:right">{{ number_format((float) $transaccion->tran_paid_amount, 2) }}</td>
            <td style="text-align:right">{{ number_format((float) $transaccion->sub_0_paid, 2) }}</td>
            <td style="text-align:right">{{ number_format((float) $transaccion->sub_16_paid, 2) }}</td>
            <td style="text-align:right">{{ number_format((float) $transaccion->tax_16_mxn, 2) }}</td>
            <td style="text-align:right">{{ number_format((float) $transaccion->tax_ret_mxn, 2) }}</td>
            <td style="text-align:right">{{ number_format((float) $transaccion->non_dec, 2) }}</td>
            <td style="text-align:right">{{ number_format((float) $transaccion->tran_paid_amount, 2) }}</td>
        </tr>
    @endforeach
    <tr>
        <td colspan="8" class="total">{{ __('impresos.total') }}</td>
        <td class="total"><strong>$ {{ number_format($total, 2) }}</strong></td>
    </tr>
</table>
