{{--
    Color de acento del cliente, aplicado en caliente.

    Va DESPUÉS de `@vite` a propósito: vuelve a declarar las mismas variables
    que Tailwind puso en `:root`, y gana la última. Gracias a esto cambiar de
    marca no obliga a recompilar los estilos (el servidor de producción ni
    siquiera tiene Node).

    Y va también el icono de la pestaña, que es marca igual que el logotipo.
--}}
@if ($favicon = \App\Support\Marca::favicon())
    <link rel="icon" href="{{ $favicon }}">
@endif
<style>{!! \App\Support\Marca::estilos() !!}</style>
