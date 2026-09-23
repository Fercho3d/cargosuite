# Generación automática de la factura y los costos de un booking

Porta `Booking::generateInvoice()`, `generateBills()`, `getInvoiceServices()`,
`getBrockerServices()` y `getServicesProvider()` del sistema Yii2 (unas 250 líneas
repartidas en `models/Booking.php`).

En el original era un botón —**Gen Bill/Invoice**— que disparaba cuatro
generaciones seguidas y volvía a la pantalla del booking con un «yes» o un «no»
por cada una. Lo que se había escrito solo se veía yendo a la lista de
transacciones, y si algo fallaba a la mitad quedaba escrito a medias.

Aquí son dos pasos: **proponer** y **escribir**.

| Pieza | Qué hace |
| --- | --- |
| `App\Support\Billing\ServiceMatcher` | Busca los servicios contratados que empatan con el booking y calcula la cantidad de cada uno. No escribe nada. |
| `App\Support\Billing\ServiceCandidate` | Un servicio que empató, con su cantidad, su divisa y su vigencia. |
| `App\Actions\Bookings\PlanBookingBilling` | Reparte los renglones elegidos en documentos (`BillingPlan` / `PlannedDocument`). |
| `App\Actions\Bookings\GenerateBookingBilling` | Escribe el plan: crea las transacciones con `SaveTransaction` y les cuelga los conceptos. |
| `App\Livewire\Operations\BillingGenerator` | La pantalla `/operacion/bookings/{id}/generar`. |

## Las reglas

Un servicio entra si está **activo**, marcado como **auto-incluible** y su ruta es
la del booking. Los campos vacíos del servicio empatan con bookings que tampoco
los tengan (`IS NULL`, no `= NULL` — ver más abajo).

| Bloque | Qué se compara | Cantidad del concepto |
| --- | --- | --- |
| **Factura al cliente** | puerto de carga, puerto de descarga, destino final, cliente y —solo si el cliente tiene `match_pickup_place`— lugar de recolección. Además el servicio tiene que ser de un tipo de contenedor que el booking lleve. | por contenedor → los contenedores de ese tipo; por BL → 1; aduana por contenedor → toda la carga; aduana por BL → 1; sin tipo de precio → 0 |
| **+ servicios de aduana** | si el booking lleva agente aduanal, se agregan los servicios del cliente con precio «de aduana» (3 y 4), sin mirar la ruta | igual que arriba |
| **Costo de la naviera** | igual que la factura, pero por proveedor. Con transportista se piden los servicios **sin** lugar de recolección (el acarreo lo cobra el otro); sin transportista, los del lugar de recolección del booking | por BL → 1; lo demás → los contenedores que empataron |
| **Costo del transportista** | puerto de carga y lugar de recolección | 1 por documento, y **un documento por contenedor y por precio que haya empatado** (ver rareza 1) |
| **Costo del agente aduanal** | solo el proveedor: sus honorarios no dependen de la ruta | por contenedor → toda la carga; por BL → 1; sin tipo de precio → 0 |

**Un documento por divisa.** Los renglones vienen ordenados por divisa y se abre
una transacción nueva cada vez que cambia: una factura no puede mezclar pesos con
dólares porque se timbra en una sola moneda. Se conserva una consecuencia del
original: los servicios de aduana se pegan al final con su propio orden, así que
si la divisa vuelve a la de un documento anterior se abre otro en vez de sumarse.

Las facturas al cliente toman folio consecutivo (`F-n`) al crearse, salvo en
cotizaciones (`mode = 9`). Eso no es cosa de aquí: lo hace `SaveTransaction`, la
misma puerta que usa el alta manual.

## Rarezas del original que se conservan a propósito

Lo que esta pieza promete es **proponer exactamente lo mismo que proponía Yii2**.
Las tres cosas que uno querría arreglar al leer el código se dejaron igual, con su
nota en el código y su prueba de paridad; la pantalla las señala para que se vean
antes de confirmar.

**1. El costo del transportista sale de un renglón único.**
La consulta pide `SUM(containers.quantity)` **sin `GROUP BY`**, así que la base
colapsa en una fila todos los precios que empataron: se queda con los datos de uno
y con una cantidad ya multiplicada por cuántos eran (precios × contenedores). Con
un solo precio por ruta —el caso normal— sale lo correcto: un costo por
contenedor. Con varios, se abren `precios × contenedores` costos, todos al primer
precio. De qué fila salen los datos lo decide el plan de ejecución, que no se
puede copiar: se toma el primero por divisa e id, y la prueba de paridad compara
booking por booking contra lo que devuelve el SQL original, así que si alguna vez
no coincidiera, se sabría. En pantalla sale el aviso de cuántos precios empataron.

**2. Un servicio dado de baja entra a la factura.**
`getBrockerServices()` es el único de los cinco caminos que no filtra por
`active`. Se deja igual; la pantalla marca el renglón en rojo.

**3. La vigencia del precio no se mira.**
`service.start_date` / `end_date` se pactan por temporadas y el emparejamiento
las ignora, así que una ruta con historia propone precios de años distintos. Se
deja igual: la vigencia se calcula solo para poder avisarla en el renglón.

Y un par de cosas donde sí se apartó, porque no cambian ni los importes ni el
número de documentos:

- **El concepto de la factura guarda de qué servicio salió.** El original lo hacía
  en los costos pero no en la factura, por un error de dedo
  (`$service->service_id = $service->service_id`, que se asigna a sí mismo). La
  columna ya existía y nadie decide nada con que esté vacía.
- **La descripción se recorta a los 100 caracteres de la columna.** En el original
  una descripción más larga hacía fallar la validación del cargo y el método se
  detenía con `exit()` **a media generación**.

## Lo que sí cambia: verlo antes de escribirlo

La propuesta llega **entera y marcada**. Confirmar sin tocar nada escribe lo mismo
que el botón viejo; lo que se gana es poder revisarla —y quitar lo que no
corresponda— en vez de descubrirlo después en la lista de transacciones.

## Qué garantiza la prueba de paridad

`tests/Feature/Billing/ServiceMatchingParityTest` (grupo `parity`) ejecuta sobre
la base real, para los 200 bookings más recientes con carga, **el SQL del
original escrito a mano** y lo compara con lo que propone `ServiceMatcher`:
mismos servicios, mismos tipos de contenedor y mismas cantidades, en los cuatro
bloques. El del transportista se compara contra el SQL literal —con su suma sin
`GROUP BY`— para verificar también **qué fila elige la base** al colapsar y que
la cantidad inflada coincide.

```bash
vendor/bin/phpunit --group parity --filter=ServiceMatchingParityTest
```

## Pendientes

- ~~El **contrato en PDF** del servicio (`service.contract`, 156 archivos) todavía
  no se sube desde Laravel.~~ Ya se sube y se abre desde la ficha del servicio y
  el listado (`App\Support\ServiceFiles`, `GET /terceros/servicios/{id}/contrato`),
  en la misma carpeta `uploads/services/{id}/pdf/` que dejó Yii2.
- Las tres rarezas de arriba están donde se pueden cambiar en un solo lugar el día
  que se decida arreglarlas, y cada una tiene una prueba que avisará del cambio.
