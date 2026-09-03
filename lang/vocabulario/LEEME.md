# Vocabularios

Cada carpeta de aquí es **cómo se llama cada cosa en un negocio**. Se elige con
`MARCA_VOCABULARIO` en el `.env` y sus archivos **ganan sobre las traducciones de
base**, así que cambiar el vocabulario no toca ni una vista.

- `servicios/` — órdenes de servicio (taller, mantenimiento, servicio técnico):
  booking → orden, contenedor → equipo, buque → máquina, naviera → fabricante.

Sin `MARCA_VOCABULARIO` se usa el de origen: agentes de carga (booking, buque,
contenedor, naviera).

## Añadir uno

1. Carpeta nueva con `es.json` y `en.json`.
2. Las **llaves son el texto en español del sistema** —tal cual, incluidos los
   acentos y los puntos finales— y el valor, cómo se dice en ese negocio.
3. Solo hacen falta las llaves que cambian; el resto cae en la base.
4. `MARCA_VOCABULARIO=<carpeta>` y `php artisan config:clear`.

`VocabularioTest` comprueba que ninguna llave de un vocabulario se haya quedado
sin existir en la base (una errata de tecleo no se nota: el texto simplemente no
cambia).
