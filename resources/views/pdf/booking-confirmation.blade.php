{{--
    Confirmación de booking, copia de `Booking::generateBokingConfirmation()` de
    Yii2. La maqueta es la que el cliente recibe desde hace años: mismas tablas,
    mismas clases, mismos textos en inglés y las mismas fechas con hora.

    Los datos de la empresa salen de `config/marca.php`. En el original iban
    escritos a mano en la plantilla, RFC incluido (FTM1507038V6), aunque el
    sistema ya facturaba con varias compañías; sigue siendo una ficha única, y
    quien la quiera por compañía tiene que pedirlo.
--}}
@php
    $fecha = fn ($valor) => empty($valor) ? 'no set' : \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y h:i:s A');
    $texto = fn ($valor) => e((string) ($valor ?? ''));
@endphp
<div class="row">
    <div class="column">
        <div class="address">
            {{ \App\Support\Marca::empresa() }}<br />
            @include('pdf.membrete')
        </div>
    </div>
    <div class="column">
        <h1 class="confirm-title" style="font-family: sans-serif">{{ $titulo }}</h1>
        <table>
            <tr>
                <td class="backcolor">{{ __('impresos.reference') }}</td>
                <td>{{ $texto($booking->booking_number) }}<br /></td>
            </tr>
            <tr>
                <td class="backcolor">{{ __('impresos.creation_date') }}</td>
                <td>{{ \Illuminate\Support\Carbon::parse($booking->created_at)->format('d/m/Y h:i:s A') }}<br/></td>
            </tr>
        </table>
    </div>
</div>

<div class="row">
    <div class="column">
        <table>
            <tr>
                <td class="backcolor">{{ __('impresos.client_information') }}</td>
            </tr>
            <tr>
                <td style="text-align: left; vertical-align: top; height:125px;">
                    {{ $texto($cliente?->fullName) }}<br /><br />
                    {{ $texto($cliente?->address) }}<br />
                    {{ $texto($cliente?->address2) }}<br />
                    {{ $texto($cliente?->city) }}, {{ $texto($cliente?->state) }} {{ $texto($cliente?->postal_code) }}.<br />
                    {{ $texto($cliente?->country) }}<br />
                </td>
            </tr>
        </table>
    </div>
    <div class="column">
        <table>
            <tr>
                <td class="backcolor" colspan="2">{{ __('impresos.delivery_address') }}</td>
            </tr>
            <tr>
                <td colspan="2">
                    {{ $texto($recoleccion?->name) }}<br /><br />
                    {{ $texto($recoleccion?->address1) }}<br />
                    {{ $texto($recoleccion?->address2) }}<br />
                    {{ $texto($recoleccion?->city) }}, {{ $texto($recoleccion?->state) }} {{ $texto($recoleccion?->postal_code) }}.<br />
                    {{ $texto($recoleccion?->country) }}<br />
                </td>
            </tr>
            <tr>
                <td class="backcolor">{{ __('impresos.spotting_date') }}</td>
                <td>{{ $fecha($continuidad?->pickup_date) }}</td>
            </tr>
        </table>
    </div>
</div>

<div class="row">
    <div class="column">
        <table id="table-1">
            <tr>
                <td class="backcolor">{{ __('impresos.carrier') }}</td>
                <td>{{ $texto($naviera?->fullName) }}</td>
            </tr>
            <tr>
                <td class="backcolor">{{ __('impresos.pick_up_place') }}</td>
                <td>{{ $texto($recoleccion?->name) }}</td>
            </tr>
            <tr>
                <td class="backcolor">{{ __('impresos.origin_port') }}</td>
                <td>{{ $texto($puertoCarga) }}</td>
            </tr>
            <tr>
                <td class="backcolor">{{ __('impresos.destination_port') }}</td>
                <td>{{ $texto($puertoDescarga) }}</td>
            </tr>
            <tr>
                <td class="backcolor">{{ __('impresos.final_destination') }}</td>
                <td>{{ $texto($destinoFinal) }}</td>
            </tr>
        </table>
        <table id="table-2">
            <tr>
                <td class="backcolor" style="width:43.5%">{{ __('impresos.cut_off_si') }}</td>
                <td>{{ $fecha($continuidad?->SI_date) }}</td>
            </tr>
            <tr>
                <td class="backcolor" style="width:43.5%">{{ __('impresos.port_closing') }}</td>
                <td>{{ $fecha($continuidad?->doc_cut_of) }}</td>
            </tr>
            <tr>
                <td class="backcolor">{{ __('impresos.departure_date') }}</td>
                <td>{{ $fecha($booking->loading_EDT) }}</td>
            </tr>
            <tr>
                <td class="backcolor">{{ __('impresos.arrival_date') }}</td>
                <td>{{ $fecha($booking->dicharge_ETA) }}</td>
            </tr>
        </table>
    </div>
    <div class="column">
        <table id="zero-table">
            <tr>
                <td class="backcolor">{{ __('impresos.remarks') }}</td>
            </tr>
            <tr>
                <td style="text-align: left; vertical-align: top; height:182px;">{!! nl2br(e((string) $booking->remarks)) !!}</td>
            </tr>
        </table>
    </div>
</div>

<div class="row">
    <div class="full-col">
        <table class="lastTable">
            <tr>
                <td class="backcolor">{{ __('impresos.number') }}</td>
                <td class="backcolor">{{ __('impresos.seal') }}</td>
                <td class="backcolor">{{ __('impresos.container_type') }}</td>
                <td class="backcolor">{{ __('impresos.commodity') }}</td>
                <td class="backcolor">{{ __('impresos.quantity') }}</td>
            </tr>
            @foreach ($contenedores as $contenedor)
                <tr>
                    <td style="text-align: left;  vertical-align: top; height:25px;">{{ $texto($contenedor->number) }}</td>
                    <td style="text-align: left;  vertical-align: top; height:25px;">{{ $texto($contenedor->seal) }}</td>
                    <td style="text-align: left;  vertical-align: top; height:25px;">{{ $texto($contenedor->container_name) }}</td>
                    <td style="text-align: left;  vertical-align: top; height:25px;">{{ $texto($contenedor->comodity) }}</td>
                    <td style="text-align: left;  vertical-align: top; height:25px;">{{ $texto($contenedor->quantity) }}</td>
                </tr>
            @endforeach
            <tr>
                <td class="backcolor"></td>
                <td class="backcolor"></td>
                <td class="backcolor"></td>
                <td class="backcolor">{{ __('impresos.totals') }}</td>
                <td class="backcolor">{{ __('impresos.pieces') }}</td>
            </tr>
            <tr>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td>{{ $piezas }}</td>
            </tr>
        </table>
    </div>
</div>

@if ($esCotizacion)
    {{-- La cotización lleva además el desglose de lo que se va a facturar. --}}
    <div class="row" style="margin-top: 20px">
        <div class="full-col">
            <table class="lastTable">
                <tr>
                    <th class="backcolor">{{ __('impresos.number') }}</th>
                    <th class="backcolor">{{ __('impresos.amount') }}</th>
                    <th class="backcolor">{{ __('impresos.currency') }}</th>
                    <th class="backcolor">TC</th>
                    <th class="backcolor">Subtotal %0</th>
                    <th class="backcolor">Subtotal %16</th>
                    <th class="backcolor">VAT 16%</th>
                    <th class="backcolor">{{ __('impresos.ret_vat') }}</th>
                    <th class="backcolor">{{ __('impresos.non_deductible') }}</th>
                    <th class="backcolor">{{ __('impresos.amount') }}</th>
                </tr>
                @foreach ($facturas as $factura)
                    <tr>
                        <td>{{ $texto($booking->booking_number) }}</td>
                        <td style="text-align: left;  vertical-align: top; height:25px;">{{ number_format((float) $factura->amount_original, 2) }}</td>
                        <td style="text-align: left;  vertical-align: top; height:25px;">{{ $texto($factura->currency) }}</td>
                        <td style="text-align: left;  vertical-align: top; height:25px;">{{ $texto($factura->exchange_value) }}</td>
                        <td style="text-align: left;  vertical-align: top; height:25px;">{{ number_format((float) $factura->sub_0_mxn, 2) }}</td>
                        <td style="text-align: left;  vertical-align: top; height:25px;">{{ number_format((float) $factura->sub_16_mxn, 2) }}</td>
                        <td style="text-align: left;  vertical-align: top; height:25px;">{{ number_format((float) $factura->tax_16_mxn, 2) }}</td>
                        <td style="text-align: left;  vertical-align: top; height:25px;">{{ number_format((float) $factura->tax_ret_mxn, 2) }}</td>
                        <td style="text-align: left;  vertical-align: top; height:25px;">{{ number_format((float) $factura->non_dec, 2) }}</td>
                        <td style="text-align: left;  vertical-align: top; height:25px;">{{ number_format((float) $factura->total_amount, 2) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td colspan="9" class="total" style="text-align: right;"><b>{{ __('impresos.total') }}</b></td>
                    <td class="total"><strong>$ {{ number_format($totalFacturado, 2) }}</strong></td>
                </tr>
            </table>
        </div>
    </div>
@endif
