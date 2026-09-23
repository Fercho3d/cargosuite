# Marca blanca: instalar el sistema para otro cliente

El sistema no lleva ningún nombre escrito en el código. Todo lo que se ve —el
nombre, el logotipo, el color, la ficha de los documentos impresos y las
direcciones de correo— sale de `config/marca.php`, y ese archivo no se toca:
se cambian las variables `MARCA_*` del `.env`.

Una instalación nueva son **tres pasos**: el bloque `MARCA_*`, el dominio y la
base de datos.

---

## 1. El bloque `MARCA_*` del `.env`

Está entero, comentado y con ejemplos en `.env.example`. Lo mínimo:

```dotenv
APP_NAME=NombreDelCliente
MARCA_NOMBRE=NombreDelCliente
MARCA_ETIQUETA=Cargo                  # etiqueta corta junto al logotipo (vacía = nada)
MARCA_LEMA="Su lema aquí"             # bajo el logotipo en la pantalla de acceso
MARCA_PIE="Sistema interno confidencial"
```

### Logotipo

Hay dos modos y **los elige la configuración, no el código**:

- **De letra** (por omisión, no necesita ningún archivo). Se parte en dos
  mitades, la segunda en el color de acento:
  ```dotenv
  MARCA_LOGO_TEXTO=Cargo
  MARCA_LOGO_ACENTO=Suite
  MARCA_LOGO_SIMBOLO=                 # opcional, va también en acento
  ```
- **De imagen**. En cuanto `MARCA_LOGO_IMAGEN` tiene algo, deja de dibujarse el
  de letra. La versión oscura es opcional: sin ella se usa la clara en los dos
  temas.
  ```dotenv
  MARCA_LOGO_IMAGEN=marca/logo.svg
  MARCA_LOGO_IMAGEN_OSCURO=marca/logo-oscuro.svg
  MARCA_FAVICON=marca/favicon.svg
  ```
  Las rutas son relativas a `public/` (o direcciones completas). Conviene un
  **SVG de altura libre**: el alto lo fija cada pantalla, no el archivo.

### Color

```dotenv
MARCA_COLOR_400="#38bdf8"
MARCA_COLOR_500="#0ea5e9"
MARCA_COLOR_600="#0284c7"
MARCA_COLOR_700="#0369a1"
MARCA_COLOR_TEXTO_CLARO="#0369a1"     # acento sobre texto, tema claro
MARCA_COLOR_TEXTO_OSCURO="#38bdf8"    # acento sobre texto, tema oscuro
```

⚠️ **Los colores van SIEMPRE entre comillas.** Sin ellas, dotenv toma la
almohadilla del `#rrggbb` como el principio de un comentario y la variable llega
vacía. (Si eso pasa, el sistema cae al color de fábrica en vez de quedarse sin
color, pero el del cliente no se aplica.)

**No hace falta recompilar los estilos.** Las utilidades de Tailwind v4 emiten
`var(--color-accent-500)`, y el layout vuelve a declarar esas variables en el
`<head>` con los valores del `.env`. Cambiar de color es reiniciar la caché de
configuración, nada más. Los grises son neutros a propósito y no se tocan.

### Vocabulario del negocio

El sistema nació para agentes de carga y habla de *bookings*, *buques* y
*contenedores*. Un taller no tiene buques: tiene órdenes de servicio y equipos.

```dotenv
MARCA_VOCABULARIO=servicios
```

Nombra una carpeta de `lang/vocabulario/`. Sus textos **ganan sobre los de base**,
así que cambiar el vocabulario **no toca ni una vista**: las llaves de traducción
son el texto en español, o sea que renombrar el dominio es traducir.

Vacío = el vocabulario de origen. Para crear uno nuevo, ver
[lang/vocabulario/LEEME.md](../lang/vocabulario/LEEME.md).

⚠️ **Ojo con las erratas**: una llave mal tecleada no falla, simplemente deja el
texto viejo en pantalla. `VocabularioTest` comprueba que todas existan en la base.

### Qué catálogos se ven

El sistema trae diecisiete y buena parte solo tienen sentido en carga marítima.

```dotenv
MARCA_CATALOGOS=hitos,monedas,bancos,tipos-cargo,companias,campos-archivo
```

Vacío = todos. Un catálogo que no esté en la lista **tampoco se alcanza
escribiendo su dirección**. Los slugs se ven en `/catalogos`.

⚠️ Misma trampa que el vocabulario: un slug mal tecleado no falla, solo hace
desaparecer el catálogo del menú. `CatalogVisibilityTest` lo vigila.

### Idioma de los documentos

```dotenv
MARCA_IDIOMA_DOCUMENTOS=es
```

Los PDF (confirmación de booking, solicitud de pago) y los correos que salen a
clientes y proveedores. **Va aparte del idioma de la interfaz** a propósito: el
documento lo lee el cliente, no el operador, así que un operador trabajando en
inglés no debe mandarle la factura en inglés a un cliente mexicano.

Por omisión es `en`, que es como los emitía el sistema anterior.

### Facturación fuera de México

Timbrar es una obligación mexicana. Donde no aplica:

```dotenv
TIMBRADO_HABILITADO=false
```

Desaparecen el timbrado, el aviso de «facturas sin timbrar» y los cuatro campos
del SAT en la ficha del cliente. **Las facturas se siguen emitiendo, imprimiendo
y cobrando igual**: lo que se apaga es el CFDI, no la facturación.

Cancelar sigue disponible si el documento ya tiene sello: una instalación que
dejó de facturar al SAT todavía puede tener que cancelar lo que timbró antes.
Los catálogos del SAT se ocultan aparte, con `MARCA_CATALOGOS`.

### Campos propios del expediente

Además de apagar los que sobran, se pueden **añadir** los que faltan desde
`/catalogos/campos-expediente`: nombre, clave, tipo (texto, número, fecha, sí/no
o desplegable), apartado, orden y si es obligatorio. Salen en el alta y en el
detalle, y se guardan como filas, así que añadir uno no toca el esquema.

Un taller añadiría «número de serie» y «horas de uso».

⚠️ La **clave** es la llave con la que se guarda el valor: renombrar la etiqueta
no pierde nada, cambiar la clave sí. Y apagar un campo no borra lo capturado.

### Una instalación de flota propia

Una empresa de camiones mueve con **su** gente y **su** equipo, no con
proveedores. Los catálogos `operadores` y `unidades` cubren eso, y el expediente
gana tres campos: operador, tractor y caja.

```dotenv
MARCA_VOCABULARIO=camiones
MARCA_EXPEDIENTE_OCULTOS=vesselId
```

⚠️ **Lo importante es la segunda línea.** El buque no existe en una empresa de
camiones: el medio es el tractor, y ese vive en `unidad` con sus placas, su
seguro y su verificación. Reciclar el catálogo de buques como «camiones»
obligaría a mantener la flota en **dos lugares**, así que el campo se apaga y
desaparece del alta, del detalle, del filtro y de la columna del listado.

Un agente de carga hace lo contrario: deja el buque encendido y apaga la flota
con `MARCA_EXPEDIENTE_OCULTOS=operadorId,unidadId,cajaId`.

### El taller y el almacén

```dotenv
MARCA_TALLER=true
```

Enciende **Mantenimiento** y **Almacén**, y el catálogo de refacciones. Solo se
enseñan con flota propia: quien subcontrata el transporte no repara nada.

Una orden de mantenimiento dice qué se le hizo a qué unidad —preventivo o
correctivo, en el taller propio o en uno externo—, a qué kilometraje, qué costó
la mano de obra y **qué refacciones se le pusieron**. Ponerlas las descuenta del
almacén en el mismo acto, y quitarlas las devuelve: es lo que evita el inventario
que nunca cuadra, que es por lo que casi nadie usa el almacén que ya tiene.

Del almacén, lo que se usa a diario no es saber qué hay, es saber **qué se está
acabando** (existencia contra el mínimo) y **a dónde se fue cada pieza** (el
kárdex, que es lo que se pide cuando el inventario no cuadra). El movimiento de
`ajuste` deja la existencia en lo que salga del conteo físico y guarda la
diferencia.

Del mantenimiento, lo que se usa es el aviso: **qué unidades ya deben servicio**,
por kilometraje y no por fecha. Se avisa en el último 10 % del intervalo —
proporcional, porque no toda unidad se sirve cada 20 000 km—, y cerrar un
preventivo le pone el reloj a cero a la unidad. Un preventivo que se avisa tarde
es un correctivo.

⚠️ `refaccion.existencia` está desnormalizada —el listado no puede sumar el
kárdex en cada renglón— pero **la verdad es el movimiento**: todo pasa por
`Inventory::mueve()`, que escribe la fila y actualiza la columna dentro de la
misma transacción, con la fila bloqueada. Escribir la columna a mano descuadra el
almacén sin que nadie sepa desde cuándo; hay una prueba que lo vigila y otra que
lo comprueba sobre los datos de ejemplo.

### La nómina

```dotenv
MARCA_NOMINA=true
```

Enciende el catálogo de **empleados** y la pantalla de **Nómina**: sueldo por los
días del periodo, las liquidaciones de viaje del operador que todavía no se han
pagado, bonos y descuentos, y el neto por persona con su CLABE para exportar.

⚠️ **Lo que no hace, y conviene decirlo antes de venderlo:** no calcula IMSS, ni
INFONAVIT, ni tablas de ISR, ni timbra el CFDI de nómina. Eso está regulado,
cambia cada año y es un producto en sí mismo. Lo que sale de aquí es el archivo
con el que se dispersa y con el que se timbra **del otro lado**.

Se apaga entera —menú y catálogo— en las instalaciones que ya llevan la nómina en
otro sistema, que son muchas: una pantalla de nómina a medio usar es peor que no
tenerla, porque se captura ahí y se paga por otro lado.

También se cambia desde **Ajustes**, sin tocar el `.env`.

Si el módulo llega a una instalación que ya está en uso, la plantilla de ejemplo
se siembra aparte y sin borrar nada:

```bash
php artisan db:seed --class=NominaDemoSeeder
```

### Los pasos del expediente (hitos)

La lista de verificación del expediente sale del **catálogo de hitos**, no de
ningún rótulo escrito en la plantilla: se ajusta desde **Catálogos → Hitos** y se
marca desde el propio expediente, con un clic por paso.

Cada vertical trae los suyos: la marítima, los quince del sistema de origen
(maniobra de vacío, recolección, corte documental, VGM, zarpe, BL…); la
terrestre, los nueve de un
viaje por carretera (asignación, llegada a carga, cargado, salida, llegada,
descargado, evidencia de entrega, facturado, liquidado al operador).

⚠️ **En una instalación nueva hay que sembrar el catálogo**, o la lista sale
vacía y no hay nada que marcar:

```bash
php artisan db:seed --class=HitosSeeder
```

No basta con migrar: `migrate` sobre una base vacía parte de
`database/schema/mysql-schema.sql`, da por corridas las migraciones anteriores y
**sus `INSERT` nunca se ejecutan**. Los datos de un catálogo van en un sembrador,
no en una migración. (Pasó en producción: la lista de verificación no dejaba
marcar nada porque no había un solo hito.)

```dotenv
MARCA_AVANCE=hitos
```

De ahí sale también el **porcentaje de avance**. El valor `verificacion` recupera
el cálculo heredado —27 casillas `_chk_date` divididas entre 26, con su 103.85 %
cuando están todas— y va **solo en la instalación original**, donde el cliente
lleva años viendo ese número. Con ese valor el detalle enseña además las
**verificaciones de datos del booking** (número, cliente, buque, POL, ETD, POD,
ETA, tipo de contenedor, mercancía, set point, lugar de recolección y
modalidad: `Checklist::DATOS_DEL_BOOKING`), que abren la lista en el sistema de
origen y forman una sola cadena con los hitos; con `hitos` no salen, porque no
cuentan para nada.

Aparte de eso, y en cualquier instalación, el detalle trae la **lista de
verificación del booking**: cinco fechas con hora en la propia tabla `booking`
(arribo, liberación de la naviera, despacho, solicitud de transporte y entrega
al consignatario, `Booking::LISTA_DE_VERIFICACION`), que marca el administrador.

#### Planeado y cumplido son dos capas

En la instalación original cada hito tiene **dos fechas**, como en el sistema de
origen: la **planeada** (`hito_por_expediente.fecha`, con espejo en su columna de
`booking_continuity`) y el **cumplimiento** (`check_list.<casilla>_chk_date` y
`_chk_by`: cuándo se marcó y quién). En el detalle del expediente la fecha
planeada se captura con el recuadro «Plan» y el cumplimiento se marca con la
palomita; debajo salen la fecha real, quién la marcó y el «delivery time»
(`App\Support\Milestones\Checklist::retraso()`). Marcar **no toca** la fecha
planeada: antes la pisaba con la de hoy y se perdía contra qué comparar.

Reglas, heredadas del original: marcar y capturar la fecha planeada (en el
detalle o en el reporte de continuidad, con hora; 00:00 si no se sabe) es de
cualquier usuario interno, pero quien no es administrador tiene que ir **en el
orden del catálogo** (la anterior marcada) y no puede tocar una ya marcada;
**desmarcar es de administradores**. **Cerrar y reabrir** un expediente es del
super administrador, como `lock` y `unlock` allá. `check_list_history` la
escribe un disparador de la base, así que cada escritura firma `modified_by`. En
el reporte de continuidad, «Ver cumplidas» enseña estas fechas en vez de las
planeadas, de solo lectura.

La casilla de cada hito es su `columna_legado`: los quince de origen la tienen.
Un hito **sin** columna heredada (los de la vertical terrestre, o uno añadido
desde el catálogo) no tiene dónde guardar una segunda fecha, así que su fecha hace
de planeada y de cumplida a la vez y se marca con la de hoy, como siempre.

### Enseñarlo con los datos del negocio que se tiene enfrente

```dotenv
MARCA_DEMO=true
```

Enciende en **Ajustes** el apartado «Datos de demostración», con un botón por
vertical que **borra la base y la vuelve a llenar** con una operación completa
—clientes, rutas, expedientes, facturas, costos, gastos y nómina— y deja además
la modalidad y el vocabulario acomodados a esa forma de transportar:

| Vertical | Perfil de datos | Cómo queda la pantalla |
|---|---|---|
| Agente de carga marítima | `carga` | modalidad marítima, vocabulario de origen |
| Autotransporte (México) | `camiones` | modalidad terrestre, vocabulario `camiones` |

Lo terrestre está armado para México y para que los números aguanten una mirada
de transportista: doce rutas reales entre patios y ciudades **con sus
kilómetros**, y de ahí cuelga todo lo demás —los días de tránsito (uno por cada
650 km, que es la jornada del operador, no la velocidad del camión), la tarifa a
28 pesos por kilómetro con mínimo por viaje, el diésel a 2.2 km/litro y las
casetas a 2.90 por kilómetro—. El flete se factura **en pesos, con IVA del 16 % y
retención del 4 %**, que es lo que hace el autotransporte de carga cuando el
cliente es persona moral. Uno de cada seis viajes va subcontratado: sin operador,
sin unidad, sin diésel y con la factura del que lo movió.

⚠️ **Es un interruptor que vacía la base.** Por eso va apagado de fábrica y solo
existe donde se declara: en la instalación de un cliente el apartado no aparece
**y la acción se niega aunque se llame a mano**. Y como el sembrador rehace
también la tabla de usuarios, al terminar se cierra la sesión a propósito: la
que estaba abierta apuntaba a una cuenta que ya no es la misma.

Las pruebas del grupo `demo` revisan esas bases sembradas —que todas las
pantallas abran y que los números tengan sentido para la vertical—:

```bash
php artisan test --group=demo
```

### ⚠️ La cookie de sesión no debe depender de la marca

`SESSION_COOKIE=app_session` va fijo en el `.env`, y conviene dejarlo así.

Laravel deriva el nombre de la cookie de `APP_NAME`. Sin fijarlo, cambiarle el
nombre al sistema **renombra la cookie**: todas las sesiones abiertas dejan de
valer y el siguiente formulario que alguien envíe responde **419 Page Expired**,
sin ninguna pista de por qué. Pasó al renombrar de FregoCargo a CargoSuite.

`SessionCookieTest` lo vigila.

### Qué campos pide el expediente

```dotenv
MARCA_EXPEDIENTE_OCULTOS=carrierId,brokerId,containerType,setPoint
```

El campo apagado desaparece del alta y del detalle, no se valida y **no se
escribe**; lo que ya estuviera guardado se queda como está. Un grupo que se
quede sin campos tampoco se pinta.

Solo se apagan los **opcionales** —la lista vive en `App\Support\Expediente`—.
Los obligatorios (folio, cliente, ruta y fechas) sostienen los listados, los
reportes y la generación de facturación; pedir apagarlos se ignora.

### Ficha de los documentos impresos

Encabeza la confirmación de booking y la solicitud de pago. El renglón vacío no
se pinta: quien no tenga RFC no ve «RFC:».

```dotenv
MARCA_EMPRESA="Nombre Legal, S.A. de C.V."
MARCA_DOMICILIO="Calle y número|Colonia|Ciudad, Estado 00000"   # « | » separa renglones
MARCA_RFC=XAXX010101000
MARCA_TELEFONO="+52 33 0000 0000"
MARCA_PORTAL=                         # vacío = APP_URL + /portal
```

### Correo

```dotenv
MARCA_MAIL_FROM=facturas@cliente.com
MARCA_MAIL_FROM_NAME="Nombre del cliente"
MARCA_MAIL_FACTURAS_BCC=copia@cliente.com     # copia oculta de las facturas
MARCA_MAIL_AVISOS=operaciones@cliente.com     # avisos de tareas atrasadas
MARCA_AVISOS_CORREO=false
```

⚠️ `MARCA_AVISOS_CORREO=true` en una instalación con historial manda **todo lo
vencido acumulado** en la primera corrida. Encenderlo después de limpiar.

Al terminar:

```bash
php artisan config:clear
```

---

## 2. El dominio

En local el sistema responde en **http://cargosuite.loc** (el vhost está en
`/opt/homebrew/etc/httpd/extra/httpd-vhosts.conf`). El nombre es genérico a
propósito: la misma instalación se le enseña a cualquier prospecto cambiando
solo el bloque `MARCA_*`.

Para dar de alta otro nombre local:

```bash
sudo sh -c 'echo "127.0.0.1 nuevo.loc www.nuevo.loc" >> /etc/hosts'
sudo apachectl -k graceful
```

y añadirlo al `ServerAlias` del vhost. Los nombres viejos
(`frego-laravel.loc`) siguen entrando por alias, así que nada se rompe.

---

## 3. La base de datos

### Demostración

`cargosuite_demo` trae una operación de ejemplo completa: 45 embarques con sus
contenedores, su lista de verificación, su factura y sus costos, un año de tipos
de cambio y las solicitudes de pago. **Nada sale de una base real**: ni un
nombre, ni un RFC, ni un importe.

Volver a generarla desde cero:

```bash
php artisan db:seed --class=DemoSeeder
```

Es determinista: dos corridas producen exactamente los mismos datos, así que una
captura de pantalla de hoy sigue valiendo mañana.

Cuentas de la demostración (contraseña `demo1234`, o la que diga `DEMO_PASSWORD`):

| Usuario | Qué ve |
|---|---|
| `demo.admin` | todo (super administrador) |
| `demo.facturacion` | facturación y pagos |
| `demo.operaciones` | operación, sin facturación |
| `demo.cliente` | portal de cliente |
| `demo.proveedor` | portal de proveedor |

### Dos salvaguardas del seeder

1. **Se niega a escribir en la base del sistema anterior** (la que declara
   `FREGO_DB_DATABASE`): es la que usan las pruebas de paridad y el seeder
   empieza vaciando tablas.
2. **Se niega a pisar una base que ya tiene transacciones**, a menos que se
   corra con `DEMO_SEED_FORCE=true`.

### Instalación de verdad

```bash
mysql -e "CREATE DATABASE <cliente> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate --force
```

⚠️ **Esto solo funciona gracias a `database/schema/mysql-schema.sql`.** Las
migraciones de este proyecto son **aditivas sobre el esquema heredado** —añaden
columnas a `users`, crean `hito`, `campo_expediente`…— y ninguna crea las 42
tablas de base. Sin ese archivo, `migrate` sobre una base vacía falla con «Table
'users' doesn't exist», que es exactamente lo que pasó al instalarlo por primera
vez en un servidor nuevo.

Laravel carga ese volcado solo cuando la base está vacía, y después aplica las
migraciones que falten. Se regenera con `php artisan schema:dump`.

⚠️ Si lo generas desde MariaDB, **quítale la primera línea** (`/*M!999999…`): es
un comentario ejecutable propio de MariaDB y MySQL 8 no lo entiende.

Después hay que capturar los catálogos del cliente (divisas, bancos, tipos de
cargo, puertos, contenedores) desde `/catalogos`, y sus clientes, proveedores y
precios.

---

## Qué NO cambia con la marca

Cosas que llevan el nombre del primer cliente por dentro y **no se ven**; se
dejaron así a propósito, porque renombrarlas es un refactor grande y de riesgo
sin nada a cambio para quien compra:

- el espacio de nombres `App\Models\Core\…`;
- las cookies `app_theme`, `app_theme_resolved` y `app_locale`;
- la conexión `frego_legacy` y la variable `FREGO_DB_DATABASE`, que solo existen
  para comparar contra el sistema anterior en las pruebas de paridad.

Lo que **sí** se limpió porque se ve: el disco de archivos (antes `frego`, ahora
`documentos`, con `DOCUMENTOS_PATH`) y el comando programado (antes
`frego:avisos-continuidad`, ahora `operacion:avisos-continuidad` — **hay que
actualizar la línea del cron al desplegar**).

Hay un guardián que lo vigila: `tests/Feature/MarcaTest` falla si alguna vista
vuelve a traer el nombre escrito a mano.
