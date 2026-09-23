# De sistema a medida a producto: los 12 puntos

Qué falta para que CargoSuite sirva a más de un tipo de negocio. Ordenado por lo
que más estorba hoy: cada punto de arriba desbloquea a los de abajo.

Estado: ⬜ pendiente · 🔄 en curso · ✅ hecho

---

## 1. 🔄 Los hitos dejan de ser columnas

Al abrirlo resultó que **eran dos cosas distintas mezcladas**, y solo una es la
que necesita redefinir un negocio nuevo:

| | Qué es | Estado |
|---|---|---|
| `booking_continuity`, 14 columnas | los **hitos**: la fecha de cada paso del expediente | ✅ hecho |
| `check_list`, 27 columnas `_chk_date` | la **verificación de campos** del booking: qué dato ya se confirmó y quién | 🔄 las casillas de los hitos ya se marcan desde el detalle (ver «Planeado y cumplido» en `MARCA-BLANCA.md`); generalizarla campo por campo va detrás del punto 2 |

Un taller no necesita «zarpe», necesita «diagnóstico» y «entrega»: eso son los
hitos. La verificación, en cambio, va campo por campo del expediente, así que no
se puede generalizar antes de generalizar el expediente (punto 2).

### Lo hecho

Tablas `hito` (catálogo) y `hito_por_expediente` (una fila por fecha), con la
migración que **rellena desde las columnas** lo que ya había. Los hitos se
capturan desde `/catalogos/hitos` como cualquier otro catálogo: añadir uno,
renombrarlo, reordenarlo o apagarlo ya no toca ni el esquema ni el código.

⚠️ **Las columnas viejas no se borran y se siguen escribiendo** (el espejo de
`BookingMilestones`), porque de ellas salen todavía cuatro cosas: el PDF de
confirmación —comparado carácter por carácter en paridad—, dos columnas y dos
filtros del listado, los avisos de tareas atrasadas, y el propio Yii2 con el que
la instalación original convive. Un hito **nuevo** no tiene columna y vive solo
en la tabla nueva; el espejo no le aplica.

### Lo que falta de este punto

· Que el listado y los avisos lean del catálogo en vez de las columnas, para que
  un hito nuevo también dispare avisos.
· El PDF de confirmación, cuando se haga el punto 10 (plantillas por instalación).
· La verificación de campos, detrás del punto 2.
· Y el defecto conocido del avance: suma 27 casillas y divide entre 26, así que
  con todo marcado da 103.85 %. Sigue igual a propósito —corregirlo mueve los
  porcentajes que el cliente ve— y se arreglará al pasar la verificación a filas,
  dejando el modo histórico como opción.

## 2. 🔄 Partir el expediente: núcleo + campos por instalación

**Hoy:** `booking` tiene **53 columnas** con vocabulario de carga marítima.

### Lo hecho: apagar lo que no aplica

`MARCA_EXPEDIENTE_OCULTOS` quita del alta y del detalle los campos opcionales
que esta instalación no pide. Un taller apaga naviera, agente aduanal, tipo de
contenedor y temperatura y deja de enseñar cuatro casillas que nadie sabe
llenar. Un grupo que se queda sin campos tampoco se pinta.

⚠️ **Lo delicado es que apagar no borre.** Si el guardado escribiera nulo en los
campos apagados, editar un expediente viejo le vaciaría datos en silencio; por
eso el campo apagado se **omite** del guardado en vez de escribirse. Hay prueba.

Solo se apagan los opcionales: los obligatorios sostienen los listados, los
reportes y la generación de facturación, y pedir apagarlos se ignora.

### Lo hecho: añadir campos propios

Tablas `campo_expediente` (catálogo) y `valor_por_expediente` (una fila por
valor), el mismo mecanismo que el sistema ya usaba para los documentos por
cliente. Se definen en `/catalogos/campos-expediente` —nombre, clave, tipo
(texto, número, fecha, sí/no, desplegable), apartado, orden y si es
obligatorio— y salen en el alta y en el detalle del expediente.

Un taller añade «número de serie» y «horas de uso» sin tocar el esquema.

Detalles que valen: el valor se guarda **como texto** a propósito (el tipo puede
cambiar y una columna por tipo obligaría a migrar datos cada vez que alguien
corrige «número» por «texto»), la clave es la llave de guardado (renombrar la
etiqueta no pierde lo capturado), y **apagar un campo tampoco borra lo
capturado**, igual que con los de siempre.

### Lo que falta de este punto

La verificación de campos del punto 1 —las 27 columnas `_chk_date` de
`check_list`—, que va campo por campo y ahora sí se puede generalizar sobre este
mecanismo.

## 3. ✅ Capa de vocabulario

**Hecho.** `MARCA_VOCABULARIO` nombra una carpeta de `lang/vocabulario/` cuyos
textos **ganan sobre los de base**, así que cambiar el vocabulario no toca ni una
vista. Va incluido `servicios` (booking → orden, contenedor → equipo, buque →
máquina, naviera → fabricante), 50 llaves en los dos idiomas.

Se descartó la idea de sustituir sustantivos sueltos: el vocabulario está dentro
de **frases completas** (82 llaves mencionan el dominio), y una sustitución ciega
rompe la concordancia («el booking» → «el orden de servicio»).

⚠️ **Dos trampas, las dos silenciosas** (fijadas en `VocabularioTest`):
· `Lang::addJsonPath()` **no sirve** para sobrescribir — añade las rutas al
  principio y `loadJsonPaths()` deja ganar a la última, o sea a `lang/`;
· el proveedor de traducciones de Laravel es **diferido**: si el enlace se cambia
  en `register()`, el proveedor se carga después y lo pisa. Va en `boot()`, y
  antes hay que forzar su registro.

## 4. ✅ El CFDI, un conector más

**Hecho.** `TIMBRADO_HABILITADO=false` apaga el CFDI sin tocar la facturación:
las facturas se siguen emitiendo, imprimiendo y cobrando. Desaparecen el
timbrado, el aviso de «facturas sin timbrar» y los cuatro campos del SAT en la
ficha del cliente.

Dos decisiones que conviene no revertir sin pensarlo:
· **Cancelar sigue disponible** si el documento ya tiene sello. Una instalación
  que dejó de facturar al SAT todavía puede tener que cancelar lo de antes.
· La comprobación va **también dentro de `StampTransaction`**, no solo en la
  pantalla: esconder el botón no protege de una consola, un trabajo en cola ni de
  una pantalla futura que se olvide de preguntar.

## 5. ✅ Emparejador de precios por atributos

**Hecho, y resultó menos de lo que parecía.** El emparejador ya generalizaba
solo: compara puerto de carga, puerto de descarga, destino final y lugar de
recolección con `IS NULL` cuando faltan, así que un negocio sin geografía —donde
tanto el precio como el expediente los dejan vacíos— **ya empataba bien**.

Lo que faltaba era poder apagar una dimensión que **sí tiene valores** pero no
debe atar el precio. `MARCA_EMPAREJADOR_DIMENSIONES` lista las que participan;
vacío = las cuatro, como siempre.

⚠️ **El cliente y el proveedor no se pueden apagar** y no pasan por el filtro: sin
ellos, el precio de un proveedor se le aplicaría a otro. Hay prueba.

⚠️ **Trampa al escribir la prueba, y de las que engañan:** el emparejador une
contra los contenedores del expediente. Sin tipo de contenedor en el precio y sin
contenedores en el booking **no empata nada nunca**, así que la prueba de «no
debe empatar» pasaba por el motivo equivocado. Se vio al hacer el ciclo al revés:
la prueba gemela, la que sí debe empatar, salía vacía también.

## 6. ✅ Una sola identidad

**Hecho, con un matiz que importa.** El acceso ya era uno solo: las 81 cuentas de
portal viven en `users` y pasan por Fortify y el 2FA. Lo que quedaba eran las
**columnas** de credencial en `client`, `provider` y `carrier` —contraseña, clave
de sesión y token de recuperación en cada una—, con 7 cuentas todavía con
contraseña puesta en la base real.

Auditado: **esta aplicación no las lee**; los modelos solo las ocultan. Así que
el riesgo no es que se usen mal aquí, es que existan.

⚠️ **No se pueden borrar sin más**: la instalación original convive con el portal
Yii2, que es lo único que las lee. Correr la limpieza allí dejaría fuera a quien
todavía entre por el portal viejo. Por eso:

· la migración que las quita vive **fuera de la corrida normal**, en
  `database/migrations/opcionales/`, y se corre a propósito:
  `php artisan migrate --path=database/migrations/opcionales`.
  Una instalación nueva la corre el primer día; la de Frego, cuando se retire el
  portal antiguo;
· `php artisan seguridad:credenciales-heredadas` dice qué se llevaría por
  delante antes de correrla;
· `CredencialesHeredadasTest` fija tres cosas: que los modelos no las expongan,
  que **ningún código de la aplicación las lea** —el día que alguien las use para
  «reaprovechar» un acceso, se pone rojo— y que la migración no se cuele en la
  corrida normal.

## 7. ✅ Catálogos por vertical

**Hecho.** `MARCA_CATALOGOS` lista los slugs visibles; vacío = los diecisiete.
Un catálogo oculto tampoco se alcanza escribiendo su dirección.

`CatalogRegistry::all()` sigue devolviendo **todos** a propósito: de ahí come el
guardián que valida cada definición contra el esquema real, y ocultar un catálogo
no puede dejarlo sin vigilar. Lo que filtra es `visibles()`, y `find()` resuelve
sobre esa.

## 8. ✅ Multiempresa: decidido

**Una instalación por cliente**, cada una en su carpeta y con su base
(Fernando, 2026-08-28). Los clientes son empresas muy separadas y no comparten
nada, así que no se paga el precio de meter el inquilino en cada consulta y en
cada tabla, ni se corre el riesgo de una fuga entre clientes.

**Consecuencias para el resto del plan:**
· **No se añade inquilino a ninguna tabla.** Los puntos 1, 2 y 9 quedan libres.
· La marca, el vocabulario, los catálogos y la base ya son por instalación: no
  hace falta nada más para aislar a un cliente de otro.
· Lo que sí encarece es **desplegar**: cada carpeta lleva su `.env`, su base, su
  carpeta de documentos y su línea de cron, y una actualización hay que subirla
  a todas. Conviene un procedimiento de alta de instalación cuando haya dos o
  tres clientes; con uno todavía no se paga solo.
· `company` se queda como la entidad **que factura** (multiemisor de CFDI), que
  es otra cosa: una misma instalación puede facturar con varios RFC.

## 9. ✅ Los reportes, sobre el expediente genérico

**Hecho, y como el 5, resultó que casi todo ya estaba.** Los reportes —utilidad
por booking, reporte por booking, continuidad— agrupan por expediente y sus
etiquetas pasan por `__()`, así que el **vocabulario** (punto 3) ya los renombra
enteros: en la instalación de taller dicen «Utilidad por orden» sin tocar nada.

Lo que faltaba de verdad era una incoherencia pequeña y molesta: **un campo
apagado seguía dejando su filtro** en el listado de expedientes. Buscar por algo
que el alta no captura es un filtro que nunca encuentra nada, y quien lo usa no
tiene manera de saber por qué. Ya no sale.

**Lo que queda fuera a propósito:** los campos propios (punto 2) no aparecen en
los reportes ni en la exportación. Meterlos exige decidir cuáles, porque una
instalación puede definir veinte y ninguna tabla aguanta veinte columnas más;
tendría que ser una elección por reporte, y eso es una pantalla nueva.

## 10. ✅ Idioma de los documentos por instalación

**Hecho.** `MARCA_IDIOMA_DOCUMENTOS` decide en qué idioma salen los PDF y los
correos. Se envolvieron los ~60 rótulos de la confirmación de booking, la
solicitud de pago y el correo de confirmación, y se añadieron los asuntos.

Dos decisiones que importan:
· **Va aparte del idioma de la interfaz.** El documento lo lee el cliente, no el
  operador: uno trabajando en inglés no debe mandarle la factura en inglés a un
  cliente mexicano. `Documentos::conIdioma()` lo cambia solo mientras se arma, y
  lo restaura en un `finally` —si la plantilla revienta a medias y no se
  restaura, el usuario ve el resto de la pantalla en otro idioma sin entender
  por qué—.
· **Por omisión sigue siendo `en`**, para que la instalación original emita
  exactamente lo mismo. La paridad lo comprueba carácter por carácter.

⚠️ **Los correos NO se arreglan envolviendo el asunto**: el cuerpo lo pinta
Laravel más tarde, ya fuera de cualquier envoltura nuestra. Hay que usar
`locale()` del propio Mailable, que cubre asunto y cuerpo.

⚠️ Y ojo con las pruebas: `$correo->envelope()->subject` se evalúa en el idioma
EN CURSO, no en el del correo. Una prueba que compare el asunto sin ponerse en
el idioma del documento falla aunque el correo se haya mandado bien.

**Falta** (menor): las plantillas en sí siguen siendo una por documento. Si un
cliente quiere otra maqueta, hoy se edita el Blade.

## 11. ⬜ Quitar el nombre de dentro

`App\Models\Core` y las cookies `app_theme`, `app_theme_resolved`,
`app_locale`. No se ve en pantalla, pero se ve en el código y en el navegador.
**Conviene hacerlo pronto**: cuanto más código se escriba encima, más caro.

## 12. ✅ Un seeder de demostración por vertical

**Hecho.** Los datos salieron de `DemoSeeder` a un **perfil**
(`database/seeders/Perfiles/`): el seeder monta siempre la misma operación y el
perfil pone los nombres —quién es el cliente, qué se trabaja, de dónde a dónde y
qué se cobra—.

```bash
DEMO_PERFIL=servicios php artisan db:seed --class=DemoSeeder
```

Van dos: `carga` (el de siempre) y `servicios` (taller: órdenes `OS-01045`,
«Mano de obra», «MÁQUINA 01», proveedores de refacciones). Se usa **junto con el
vocabulario**: `MARCA_VOCABULARIO=servicios` cambia las etiquetas y
`DEMO_PERFIL=servicios` los datos. Probado de punta a punta sembrando una segunda
base, que es además el ensayo del alta de un cliente nuevo según el punto 8.

⚠️ **Lo que vigila `PerfilDemoTest` son los largos.** El esquema heredado tiene
columnas cortísimas —`charge_type_name` admite 25 caracteres y `service.name`
solo 20— y un nombre que no cabe revienta **al sembrar**, o sea cuando ya le
estás enseñando el sistema a alguien. Pasó con «Gastos por cuenta del cliente»,
de 29.

## 13. ✅ Mapa de rutas en el panel

**Hecho.** Migración que añade latitud y longitud a los cuatro catálogos de lugar
—no las tenían, era el dato que faltaba—, capturables desde `/catalogos`, y un
mapa en el panel con las rutas vivas: origen, destino, la curva entre los dos y
el medio en su posición, más la lista con el avance de cada una.

**SVG servido por la propia aplicación**, sin Google Maps ni Mapbox: sin llave
que gestionar, sin factura por carga, sin mandarle a un tercero por dónde se
mueve la carga del cliente, y se ve igual en tema claro y oscuro. El dibujo del
mundo es **opcional** (`MARCA_MAPA_FONDO`): sin él quedan la retícula y las
rutas. Natural Earth publica mapas de dominio público que encajan.

⚠️ **La posición es una ESTIMACIÓN por fechas, y la pantalla lo dice.** El
sistema no sabe dónde está el barco: no hay una sola posición en ninguna tabla y
no la habrá sin contratar AIS, que es una suscripción aparte. Un punto preciso
sobre el océano que en realidad es una regla de tres se ve muy bien hasta que el
cliente lo compara con la realidad, y entonces se vuelve en contra.

Decisiones que sostienen eso: un lugar **sin coordenadas no se dibuja** —no se
inventa una posición para rellenar el mapa— y un embarque **sin fecha de arribo
se queda en el origen**, no a medio océano. Las dos con prueba.

La curva no es adorno: dos rutas entre los mismos puertos se dibujarían una
encima de otra y parecerían una sola.

---

## Aparte: la base de datos

Diagnóstico completo y plan de migraciones en
[DIAGNOSTICO-BASE-DE-DATOS.md](DIAGNOSTICO-BASE-DE-DATOS.md).
