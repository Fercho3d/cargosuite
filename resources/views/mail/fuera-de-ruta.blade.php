{{-- Aviso interno de operación: una unidad se salió de la ruta de su viaje. --}}
<p>{{ __('La unidad :unidad del viaje :viaje se salió de su ruta planeada: está a :km km de ella.', ['unidad' => $unidad, 'viaje' => $viaje, 'km' => number_format($distanciaKm, 1)]) }}</p>
<p>
    <a href="https://www.openstreetmap.org/?mlat={{ $lat }}&mlon={{ $lng }}#map=13/{{ $lat }}/{{ $lng }}">{{ __('Ver dónde está') }}</a>
    · <a href="{{ route('fleet.map') }}">{{ __('Abrir el mapa de la flota') }}</a>
</p>
<p style="color:#64748b;font-size:12px">{{ __('Este aviso se manda una vez por desvío. Cuando la unidad vuelve a la ruta, la alerta se cierra sola.') }}</p>
