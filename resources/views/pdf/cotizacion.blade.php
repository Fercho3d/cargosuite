{{--
    Cotización de viaje. Comparte hoja de estilos con la solicitud de pago
    (`payment-request.css`): mismo encabezado, cajas, tabla y pie.
--}}
@php $n = fn ($v) => number_format((float) $v, 2); @endphp

<htmlpagefooter name="pie">
    <table class="pie"><tr>
        <td>{{ \App\Support\Marca::empresa() }} · {{ __('impresos.quote_title') }} {{ $c->numero }}</td>
        <td class="der">{{ __('impresos.page') }} {PAGENO} / {nbpg}</td>
    </tr></table>
</htmlpagefooter>

<table class="encabezado">
    <tr>
        <td class="marca">
            <div class="empresa" style="color: {{ $color }}">{{ \App\Support\Marca::empresa() }}</div>
            <div class="membrete">@include('pdf.membrete')</div>
        </td>
        <td class="folio">
            <div class="titulo">{{ __('impresos.quote_title') }}</div>
            <table class="datos-folio">
                <tr><td class="etq">{{ __('impresos.folio') }}</td><td class="val folio-num" style="color: {{ $color }}">{{ $c->numero }}</td></tr>
                <tr><td class="etq">{{ __('impresos.date') }}</td><td class="val">{{ $fecha }}</td></tr>
                <tr><td class="etq">{{ __('impresos.valid_until') }}</td><td class="val">{{ $vigencia }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="franja" style="background-color: {{ $color }}"></div>

<table class="bloque">
    <tr>
        <td class="caja beneficiario">
            <div class="etq">{{ __('impresos.customer') }}</div>
            <div class="nombre">{{ $destinatario }}</div>
            @if ($rfc)
                <div class="dato">RFC {{ $rfc }}</div>
            @endif
            @if ($c->correo)
                <div class="dato">{{ $c->correo }}</div>
            @endif
        </td>
        <td class="separa"></td>
        <td class="caja importe" style="border: 0.6mm solid {{ $color }}">
            <div class="etq">{{ __('impresos.route') }}</div>
            <div class="nombre">{{ $ruta->origen }} → {{ $ruta->destino }}</div>
            <div class="dato">{{ number_format((float) $ruta->km) }} km
                @if ($ruta->horas) · {{ __('impresos.approx_hours', ['h' => (float) $ruta->horas]) }} @endif
            </div>
            @if ($fechaCarga)
                <div class="dato">{{ __('impresos.loading_date') }}: {{ $fechaCarga }}</div>
            @endif
            @if ($c->tipo_unidad)
                <div class="dato">{{ __('impresos.unit_type') }}: {{ $c->tipo_unidad }}</div>
            @endif
        </td>
    </tr>
</table>

<div class="seccion">{{ __('impresos.concepts') }}</div>
<table class="docs">
    <thead>
        <tr style="background-color: {{ $color }}">
            <th class="izq">{{ __('impresos.concept') }}</th>
            <th>{{ __('impresos.quantity') }}</th>
            <th>{{ __('impresos.unit_price') }}</th>
            <th>{{ __('impresos.amount') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($renglones as $i => $r)
            <tr class="{{ $i % 2 ? 'par' : '' }}">
                <td class="izq">{{ $r->concepto }}</td>
                <td>{{ (float) $r->cantidad }}</td>
                <td>{{ $n($r->precio) }}</td>
                <td class="fuerte">{{ $n($r->cantidad * $r->precio) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totales">
    <tr><td class="hueco"></td><td class="etq-total">{{ __('impresos.subtotal') }}</td><td class="val-total">$ {{ $n($totales['subtotal']) }}</td></tr>
    <tr><td class="hueco"></td><td class="etq-total">{{ __('impresos.vat') }}</td><td class="val-total">$ {{ $n($totales['iva']) }}</td></tr>
    @if ($totales['retencion'] > 0)
        <tr><td class="hueco"></td><td class="etq-total">{{ __('impresos.ret_vat') }}</td><td class="val-total">− $ {{ $n($totales['retencion']) }}</td></tr>
    @endif
    <tr><td class="hueco"></td><td class="etq-total gran">{{ __('impresos.total') }}</td><td class="val-total gran" style="color: {{ $color }}">$ {{ $n($totales['total']) }} MXN</td></tr>
</table>

@if ($c->condiciones)
    <div class="seccion">{{ __('impresos.conditions') }}</div>
    <div class="letra">{!! nl2br(e($c->condiciones)) !!}</div>
@endif

<table class="firmas">
    <tr>
        <td class="firma">
            <table>
                <tr><td class="nombre-firma">{{ $elaboro }}&nbsp;</td></tr>
                <tr><td class="rol">{{ __('impresos.prepared_by') }}</td></tr>
            </table>
        </td>
        <td class="firma"></td>
        <td class="firma">
            <table>
                <tr><td class="nombre-firma">&nbsp;</td></tr>
                <tr><td class="rol">{{ __('impresos.accepted_by_customer') }}</td></tr>
            </table>
        </td>
    </tr>
</table>
