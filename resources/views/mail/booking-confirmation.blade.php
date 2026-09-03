{{--
    Aviso al cliente de que su booking quedó en firme, con el PDF adjunto.
    Copia de `mail/createdBookingMail.php` de Yii2: mismos campos, mismo orden y
    las mismas fechas en d/m/Y.
--}}
@php
    $fecha = fn ($valor) => empty($valor) ? '' : \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y');
@endphp
<h1>{{ __('Confirmación de booking') }}: {{ $booking->booking_number }}</h1>

@php $portal = \App\Support\Marca::portal(); @endphp
<p>{{ __('Más detalles en') }} <a href="{{ $portal }}">{{ $portal }}</a></p>

@if (! empty($cliente?->notification_notes))
    <p>{!! nl2br(e($cliente->notification_notes)) !!}</p>
@endif

<div class="container">
    <table class="details center" style="width:60%" border="1" cellspacing="0" cellpadding="4">
        @foreach ([
            [__('Id del booking'), $booking->booking_id],
            [__('Número de booking'), $booking->booking_number],
            [__('Buque'), $buque],
            [__('Naviera'), $naviera],
            [__('HB'), $booking->HB],
            [__('Cliente'), $cliente?->fullName],
            [__('Referencia del cliente'), $booking->customer_reference],
            [__('POL'), $puertoCarga],
            [__('Carga estimada'), $fecha($booking->loading_EDT)],
            [__('Puerto de descarga'), $booking->dicharge_port],
            [__('Arribo estimado'), $fecha($booking->dicharge_ETA)],
            [__('Temperatura'), $booking->set_point],
            [__('Destino final'), $booking->final_destination],
            [__('Fecha de creación'), $fecha($booking->created_at)],
            [__('Lugar de recolección'), $lugarRecoleccion],
        ] as [$etiqueta, $valor])
            <tr>
                <th style="text-align:left">{{ $etiqueta }}</th>
                <td>{{ $valor }}</td>
            </tr>
        @endforeach
    </table>

    @if ($contenedores->isNotEmpty())
        <h1>{{ __('Contenedores') }}</h1>

        <table class="details center cien" style="width:80%" border="1" cellspacing="0" cellpadding="4">
            <tr>
                <th>#</th>
                <th>{{ __('Mercancía') }}</th>
                <th>{{ __('Tipo de contenedor') }}</th>
                <th>{{ __('Sello') }}</th>
                <th>{{ __('Número') }}</th>
            </tr>
            @foreach ($contenedores as $indice => $contenedor)
                <tr>
                    <td>{{ $indice + 1 }}</td>
                    <td>{{ $contenedor->comodity }}</td>
                    <td>{{ $contenedor->quantity }}x{{ $contenedor->container_name }}</td>
                    <td>{{ $contenedor->seal }}</td>
                    <td>{{ $contenedor->number }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</div>
