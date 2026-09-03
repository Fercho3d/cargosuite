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
