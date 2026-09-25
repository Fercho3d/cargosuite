{{--
    Solicitud de pago o de cobro. Diseño propio de CargoSuite (ya no el «cheque»
    del sistema anterior): encabezado con la marca, a quién y cuánto con letra,
    el desglose de los documentos que cubre y espacio para firmas.

    mPDF no maneja flexbox: todo el acomodo va con tablas.
--}}
@php
    $n = fn ($v) => number_format((float) $v, 2);
    $titulo = $esCobro ? __('impresos.collection_request_title') : __('impresos.payment_request_title');
@endphp

<htmlpagefooter name="pie">
    <table class="pie"><tr>
        <td>{{ \App\Support\Marca::empresa() }} · {{ $titulo }} {{ $solicitud->number }}</td>
        <td class="der">{{ __('impresos.page') }} {PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpagefooter>

{{-- Encabezado: marca a la izquierda, folio y fecha a la derecha --}}
<table class="encabezado">
    <tr>
        <td class="marca">
            @if ($logo)
                <img src="{{ $logo }}" class="logo">
            @else
                <div class="empresa" style="color: {{ $color }}">{{ \App\Support\Marca::empresa() }}</div>
            @endif
            <div class="membrete">@include('pdf.membrete')</div>
        </td>
        <td class="folio">
            <div class="titulo">{{ $titulo }}</div>
            <table class="datos-folio">
                <tr><td class="etq">{{ __('impresos.folio') }}</td><td class="val folio-num" style="color: {{ $color }}">{{ $solicitud->number }}</td></tr>
                <tr><td class="etq">{{ __('impresos.date') }}</td><td class="val">{{ $fecha }}</td></tr>
                <tr><td class="etq">{{ __('impresos.currency') }}</td><td class="val">{{ $divisa }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="franja" style="background-color: {{ $color }}"></div>

{{-- A quién y cuánto --}}
<table class="bloque">
    <tr>
        <td class="caja beneficiario">
            <div class="etq">{{ $esCobro ? __('impresos.customer') : __('impresos.pay_to') }}</div>
            <div class="nombre">{{ $beneficiario }}</div>
            @if ($rfc)
                <div class="dato">RFC {{ $rfc }}</div>
            @endif
            @if ($banco || $cuentaBancaria)
                <div class="dato">{{ __('impresos.bank_account') }}: {{ trim($banco.' '.$cuentaBancaria) }}</div>
            @endif
        </td>
        <td class="separa"></td>
        <td class="caja importe" style="border: 0.6mm solid {{ $color }}">
            <div class="etq">{{ __('impresos.amount') }}</div>
            <div class="cifra" style="color: {{ $color }}">$ {{ $importe }}</div>
            <div class="divisa">{{ $divisa }}</div>
        </td>
    </tr>
</table>

<div class="letra">
    <span class="etq">{{ __('impresos.amount_in_words') }}:</span> {{ $importeEnLetra }}
</div>

{{-- Documentos que cubre --}}
<div class="seccion">{{ __('impresos.documents_covered') }}</div>
<table class="docs">
    <thead>
        <tr style="background-color: {{ $color }}">
            <th class="izq">{{ __('impresos.number') }}</th>
            <th class="izq">{{ __('Booking') }}</th>
            <th>{{ __('impresos.subtotal_0') }}</th>
            <th>{{ __('impresos.subtotal_16') }}</th>
            <th>{{ __('impresos.vat_16') }}</th>
            <th>{{ __('impresos.ret_vat') }}</th>
            <th>{{ __('impresos.non_deductible') }}</th>
            <th>{{ __('impresos.amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($transacciones as $i => $t)
            <tr class="{{ $i % 2 ? 'par' : '' }}">
                <td class="izq">{{ $t->tran_number }}</td>
                <td class="izq">{{ $t->booking_number }}</td>
                <td>{{ $n($t->sub_0_paid) }}</td>
                <td>{{ $n($t->sub_16_paid) }}</td>
                <td>{{ $n($t->tax_16_mxn) }}</td>
                <td>{{ $n($t->tax_ret_mxn) }}</td>
                <td>{{ $n($t->non_dec) }}</td>
                <td class="fuerte">{{ $n($t->tran_paid_amount) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td class="izq" colspan="2">{{ __('impresos.total') }} · {{ trans_choice('impresos.documents_count', $transacciones->count(), ['n' => $transacciones->count()]) }}</td>
            <td>{{ $n($totales['sub_0_paid']) }}</td>
            <td>{{ $n($totales['sub_16_paid']) }}</td>
            <td>{{ $n($totales['tax_16_mxn']) }}</td>
            <td>{{ $n($totales['tax_ret_mxn']) }}</td>
            <td>{{ $n($totales['non_dec']) }}</td>
            <td class="fuerte">$ {{ $n($totales['tran_paid_amount']) }}</td>
        </tr>
    </tfoot>
</table>

{{-- Firmas --}}
<table class="firmas">
    <tr>
        @foreach ([__('impresos.prepared_by'), __('impresos.authorized_by'), $esCobro ? __('impresos.paid_by') : __('impresos.received_by')] as $i => $rol)
            <td class="firma">
                <table>
                    <tr><td class="nombre-firma">{{ $i === 0 ? $elaboro : '' }}&nbsp;</td></tr>
                    <tr><td class="rol">{{ $rol }}</td></tr>
                </table>
            </td>
        @endforeach
    </tr>
</table>
