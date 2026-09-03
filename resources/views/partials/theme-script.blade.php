{{--
    Aplica el tema antes del primer pintado para evitar el parpadeo blanco.
    Va en línea y lo más arriba posible del <head>, antes de los estilos.
--}}
<script>
    (function () {
        var raiz = document.documentElement;
        var tema = raiz.dataset.theme || 'system';
        var oscuro = tema === 'dark' ||
            (tema === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

        raiz.classList.toggle('dark', oscuro);
        raiz.style.colorScheme = oscuro ? 'dark' : 'light';
    })();
    window.rutaPreferenciaTema = @json(route('preferences.theme'));
    window.rutaPreferenciaIdioma = @json(route('preferences.locale'));
</script>
