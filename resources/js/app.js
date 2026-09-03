/**
 * Tema claro/oscuro.
 *
 * La clase `dark` de <html> la pone un script en línea del <head> antes de
 * pintar, para que no haya parpadeo. Aquí solo vive lo que ocurre después: el
 * selector del usuario y el seguimiento del tema del sistema operativo.
 */
const consultaOscuro = window.matchMedia('(prefers-color-scheme: dark)');

function aplicarTema(tema) {
    const raiz = document.documentElement;
    const oscuro = tema === 'dark' || (tema === 'system' && consultaOscuro.matches);

    raiz.dataset.theme = tema;
    raiz.classList.toggle('dark', oscuro);
    raiz.style.colorScheme = oscuro ? 'dark' : 'light';

    // El servidor no puede saber qué tema tiene el sistema operativo. Se lo
    // dejamos aquí para que pinte el <html> ya correcto en la siguiente carga.
    document.cookie = `app_theme_resolved=${oscuro ? 'dark' : 'light'};path=/;max-age=31536000;samesite=lax`;
}

/*
 * Al navegar con `wire:navigate`, Livewire copia los atributos del <html> que
 * venga en la respuesta y borra los que falten. El tema hay que volver a
 * aplicarlo: `data-theme` sí llega del servidor, la clase resuelta no siempre.
 */
document.addEventListener('livewire:navigated', () => {
    aplicarTema(document.documentElement.dataset.theme || 'system');
});

// Si el usuario eligió "Sistema", seguimos los cambios del sistema en vivo.
consultaOscuro.addEventListener('change', () => {
    if (document.documentElement.dataset.theme === 'system') {
        aplicarTema('system');
    }
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('themeSwitcher', () => ({
        tema: document.documentElement.dataset.theme || 'system',

        opciones: [
            { valor: 'light', etiqueta: 'Claro' },
            { valor: 'dark', etiqueta: 'Oscuro' },
            { valor: 'system', etiqueta: 'Sistema' },
        ],

        seleccionar(tema) {
            if (tema === this.tema) {
                return;
            }

            this.tema = tema;

            // La transición se activa solo durante el cambio para que el resto
            // de la interfaz no herede animaciones de color.
            document.documentElement.classList.add('theme-transition');
            aplicarTema(tema);
            window.setTimeout(() => document.documentElement.classList.remove('theme-transition'), 250);

            this.guardar(tema);
        },

        guardar(tema) {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;

            fetch(window.rutaPreferenciaTema, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token ?? '',
                },
                body: JSON.stringify({ theme: tema }),
            }).catch(() => {
                // Si falla la red, el tema sigue aplicado en esta pestaña; se
                // volverá a intentar la próxima vez que el usuario lo cambie.
            });
        },
    }));
});

/**
 * Idioma de la interfaz.
 *
 * A diferencia del tema, cambiar de idioma exige volver a pedir la página: los
 * textos los pinta el servidor. Se guarda la preferencia y se recarga.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('localeSwitcher', () => ({
        idioma: document.documentElement.lang || 'es',

        opciones: [
            { valor: 'es', etiqueta: 'Español', corto: 'ES' },
            { valor: 'en', etiqueta: 'English', corto: 'EN' },
        ],

        seleccionar(idioma) {
            if (idioma === this.idioma) {
                return;
            }

            this.idioma = idioma;

            const token = document.querySelector('meta[name="csrf-token"]')?.content;

            fetch(window.rutaPreferenciaIdioma, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token ?? '',
                },
                body: JSON.stringify({ locale: idioma }),
            })
                .then(() => window.location.reload())
                .catch(() => window.location.reload());
        },
    }));
});

/**
 * Ancho de columnas ajustable a mano.
 *
 * Los anchos NO se escriben en cada `<th>`: Livewire vuelve a pintar la tabla en
 * cada filtro y se perderían. Se escriben como reglas CSS en un `<style>` con
 * `wire:ignore` —que el morph no toca— y se guardan en este navegador, así que
 * cada quien deja el listado como le acomoda sin tocar la base de datos.
 *
 * Arrastrar el borde derecho de la cabecera cambia el ancho; doble clic lo
 * devuelve a como estaba.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('columnResizer', (llave) => ({
        anchos: {},
        minimo: 56,

        init() {
            try {
                this.anchos = JSON.parse(localStorage.getItem(llave) || '{}');
            } catch {
                this.anchos = {};
            }

            this.pintar();
        },

        pintar() {
            const id = this.$el.id;

            this.$refs.reglas.textContent = Object.entries(this.anchos)
                .map(
                    ([columna, ancho]) =>
                        `#${id} th:nth-child(${columna}),#${id} td:nth-child(${columna})` +
                        `{width:${ancho}px;max-width:${ancho}px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}`,
                )
                .join('');
        },

        guardar() {
            Object.keys(this.anchos).length
                ? localStorage.setItem(llave, JSON.stringify(this.anchos))
                : localStorage.removeItem(llave);
        },

        arrastrar(evento, columna) {
            evento.preventDefault();

            const celda = evento.target.closest('th');
            const desdeX = evento.clientX;
            const desdeAncho = celda.offsetWidth;

            const mover = (e) => {
                this.anchos[columna] = Math.max(this.minimo, Math.round(desdeAncho + e.clientX - desdeX));
                this.pintar();
            };

            const soltar = () => {
                document.removeEventListener('mousemove', mover);
                document.removeEventListener('mouseup', soltar);
                document.body.classList.remove('select-none');
                this.guardar();
            };

            // Sin esto, arrastrar selecciona el texto de la tabla.
            document.body.classList.add('select-none');
            document.addEventListener('mousemove', mover);
            document.addEventListener('mouseup', soltar);
        },

        restablecer(columna) {
            delete this.anchos[columna];
            this.guardar();
            this.pintar();
        },
    }));
});

/**
 * Entrada de pantalla.
 *
 * `wire:navigate` cambia el contenido sin recargar, y el salto se sentía seco.
 * El `<main>` ya trae la clase puesta por el servidor (así también se anima la
 * primera carga); aquí solo hay que volver a dispararla en cada navegación.
 *
 * No se anima en los cambios normales de Livewire —filtrar, paginar, marcar una
 * casilla—: eso sería un parpadeo constante y molesto.
 */
document.addEventListener('livewire:navigated', () => {
    const pantalla = document.querySelector('[data-pantalla]');

    if (!pantalla) {
        return;
    }

    pantalla.classList.remove('page-enter');
    // Leer una medida obliga al navegador a rehacer el cálculo: sin esto, quitar
    // y poner la clase en el mismo cuadro no reinicia la animación.
    void pantalla.offsetWidth;
    pantalla.classList.add('page-enter');
});
