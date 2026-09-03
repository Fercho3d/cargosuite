{{--
    Ficha de la empresa que encabeza los documentos impresos.

    En el sistema original iba escrita dentro de cada plantilla —domicilio y RFC
    incluidos— y había que tocar el código para cambiarla. Ahora sale de
    `config/marca.php`, y el renglón que no tenga valor no se pinta: una
    instalación sin RFC no enseña «RFC:».

    El domicilio admite varios renglones separados por «|».
--}}
@php
    $lineas = array_values(array_filter(array_map('trim', explode('|', (string) config('marca.empresa.domicilio')))));
    $rfc = trim((string) config('marca.empresa.rfc'));
    $telefono = trim((string) config('marca.empresa.telefono'));
@endphp
@foreach ($lineas as $linea){{ $linea }}<br />
@endforeach
@if ($rfc)RFC:{{ $rfc }}<br />
@endif
@if ($telefono)Tel: {{ $telefono }}<br />
@endif
