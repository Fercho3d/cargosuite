{{-- Correo con la cotización adjunta. Corto a propósito: lo importante va en el PDF. --}}
<p>{{ __('Hola, :nombre:', ['nombre' => $destinatario]) }}</p>

<p>{{ __('Te compartimos la cotización :numero para la ruta :ruta, por un total de $:total MXN con impuestos. Vale hasta el :vigencia.', [
    'numero' => $c->numero,
    'ruta' => $ruta,
    'total' => number_format($total, 2),
    'vigencia' => \Illuminate\Support\Carbon::parse($c->vigencia)->format('d/m/Y'),
]) }}</p>

<p>{{ __('El detalle y las condiciones van en el PDF adjunto. Para confirmarla, solo responde este correo.') }}</p>

<p>{{ \App\Support\Marca::empresa() }}</p>
