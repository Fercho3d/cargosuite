{{-- Lo que contesta el SAT, con color: vigente en verde, cancelado en rojo y
     cualquier otra cosa (no encontrado, en proceso) en ámbar para revisarla. --}}
@props(['estado'])

@php
    $tono = match (true) {
        $estado === 'Vigente' => 'badge-ok',
        str_starts_with($estado, 'Cancelado'), $estado === 'Plazo vencido', $estado === 'Solicitud rechazada' => 'badge-danger',
        default => 'badge-warn',
    };
@endphp

<span class="badge {{ $tono }}">{{ __($estado) }}</span>
