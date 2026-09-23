# Lo que se encontró al migrar

Cosas del sistema actual que salieron a la luz al portarlo, con la evidencia de
cada una. **No son opiniones sobre el código**: son cosas que hoy afectan a la
operación o que llevan años apagadas sin que nadie lo note.

Ordenadas por lo que cuestan.

## Rotas

**El correo de confirmación al cliente no sale, y además tumba la pantalla.**
La plantilla pide `carrierModel->name`; la naviera es un `Provider` y esa columna
se llama `fullName`. Comprobado ejecutando Yii2 contra la base real:

```
yii\base\UnknownPropertyException: Getting unknown property: app\models\Provider::name
```

Como el envío se llama sin `try` justo después de guardar, dar de alta un booking
con naviera —o sea, casi cualquiera— termina en error, aunque el booking sí queda
guardado. En la aplicación nueva ya funciona.

**El costo del transportista se multiplica cuando hay más de un precio.**
La consulta pide `SUM(containers.quantity)` sin `GROUP BY`, así que la base
colapsa en una sola fila todos los precios que empataron: se queda con los datos
de uno y con una cantidad ya multiplicada por cuántos eran. En la base local hay
rutas con **cinco** precios del mismo transportista. El booking 5426 habría
generado 5 costos de $28,000 —$140,000— cuando el costo real, capturado a mano,
fue de $19,132. La aplicación nueva lo replica igual pero lo dice en pantalla
antes de escribir nada.

**La bitácora del booking dejó de escribirse en diciembre de 2021.**
`booking_history` tiene 9,933 renglones y el último es del 21/12/2021. Las otras
tres bitácoras —contenedores, continuidad y lista de verificación— siguen vivas y
llegan hasta junio de 2026. Las escribe la base de datos, no la aplicación, así
que lo más probable es que ese disparador se haya perdido en algún cambio de
servidor. Hoy nadie puede saber quién cambió un dato del embarque.

## Se cobra o se factura de más

**Un precio dado de baja sigue entrando a la factura.** De los cinco caminos por
los que se juntan los servicios de una factura, el de despacho aduanal es el
único que no filtra por «activo».

**El catálogo de precios tiene vigencia y nadie la mira.** Los precios se pactan
por temporadas (`start_date`, `end_date`) y el emparejamiento las ignora: una ruta
con historia propone precios de años distintos mezclados. En la base local, una
ruta Veracruz–Rotterdam empata con **diez** precios de meses diferentes.

**Al armar una solicitud de pago se podía aplicar más de lo que se debe.** La
validación existía en el sistema viejo; era lo único de esa pantalla que no se
había portado. Se portó, pero al principio con un tope de más: el viejo usa dos
según la pantalla (`modeopen` en `set-amount-to-pay`), y aquí se había tomado
el de la solicitud reabierta (saldo más lo pagado, o sea el total del
documento) también para el alta. Un costo de 1,000 con 600 pagados en otra
solicitud admitía hasta 1,000. Ahora el alta topa en el saldo, como la columna
«To pay» del viejo, y la reabierta en el saldo más lo aplicado en esa misma
solicitud, que es más estricto que el viejo (él sumaba también lo pagado por
otras solicitudes). Además el alta rechaza con motivo las saldadas y las
canceladas, que en el viejo simplemente no tenían casilla.

## Apagado hace años

| Qué | Desde cuándo | Evidencia |
| --- | --- | --- |
| El botón «Accept» del booking | mayo de 2022 | El método empieza con `if (date('Y-m-d') >= '2022-05-07') return false;` |
| Proveedores por booking | mayo de 2021 | 7 renglones en toda la historia de la tabla |
| Movimientos de banco | enero de 2024 | 4 renglones |
| Contratos en PDF de los servicios | 2023 | 156 archivos, ninguno más reciente |
| La tabla `carrier_booking` | siempre | 0 renglones |
| `site/contact` | siempre | La acción existe, pero `AccessControl` de `SiteController` no la deja pasar a nadie |
| Recuperar la contraseña | siempre | Están las vistas `recoverPass` y `ressetPass`, pero ninguna acción las pinta |

## Código que no hace lo que dice

- **`transaction/custom-pdf`** llama a dos métodos que no existen en ningún modelo
  y arma la ruta del archivo con una variable vacía. Invocarlo es un error fatal.
- **Las acciones `auto` y `transport`** del booking son depuración: imprimen con
  `print_r` y terminan con `exit`.
- **El avance de la lista de verificación esconde tres números**: la tabla tiene
  28 casillas, el cálculo cuenta 27 y divide entre 26. Con las 27 marcadas
  mostraría 103.85 %. Se conservó tal cual para no mover los porcentajes que el
  cliente ve hoy.
- **«Gated Out» está dos veces** en la lista de hitos que vigilan los avisos, así
  que ese hito manda dos correos iguales. En el nuevo ya no se repite: sale un
  solo correo.
- **El total de la cotización arranca con el número de piezas**: reutiliza la
  variable que venía sumando los contenedores.
- **Los avisos de tareas atrasadas eran dos direcciones web**: cualquiera que
  diera con ellas disparaba los correos a toda la operación. Ahora son un comando
  del servidor.
- **`TransactionController::actionPay` y `PaymentRequestController::actionPay`**
  terminan llamando a `Transaction::paid()`, que no existe: marcar como pagado
  desde ahí es un error fatal.
- **`actionGlobalReport` (`TransactionReport`)** sale siempre vacío: filtra por
  una variable `$booking` que nunca se define. `actionBookingDetail` tiene el
  mismo defecto (`Booking::findOne($booking)` sin `$booking`).
- **`actionChargeDetail`, `actionUpdateAjax` y `actionUpdateType`** de
  transacciones no las llama ninguna vista ni ningún script.
- **El catálogo `holiday`** no abre: su controlador usa `HolidaySearch`, que no
  existe.
- **`loading-ports` (en plural)** filtra por la columna `delted`, con errata; la
  consulta revienta. El catálogo que sí se usa es `loading-port`.
- **Borrar un destino final (`final-destination/delete`)** pone `active = 0` y
  no guarda: el registro sigue igual.

## Corregido en la reescritura (septiembre 2026)

Lo que la auditoría de paridad encontró mal en el sistema viejo y quedó
arreglado en el nuevo (lo que ya se cuenta arriba no se repite):

- Lo pagado de una transacción (`paid_amount`, `paid`, `paid_at`) se recalcula
  desde sus pagos cada vez que cambian; `payTran()` lo acumulaba a mano sobre
  valores ya leídos y podía duplicarlo o dejar mal el estado.
- La cuenta de portal tiene que quedar ligada a un cliente o proveedor que
  exista; el viejo solo pedía que fuera un número.
- Nadie puede darse de baja ni quitarse el rol a sí mismo, y el último super
  administrador activo no se puede desactivar ni degradar: en el viejo bastaba
  editar la cuenta para quedarse sin nadie que administrara usuarios.
- Rol, acceso, estado y el cliente o proveedor de una cuenta ya no se asignan
  en masa desde lo que llegue del formulario.
- La recuperación de contraseña funciona y una cuenta dada de baja no la puede
  usar para volver a entrar.

## Seguridad

- El servidor de producción tiene **MySQL escuchando en `0.0.0.0:3306`** y existe
  el usuario `fregodb@%`. No se tocó.
- El timbrado decidía si era producción **por el nombre del servidor**, y su propio
  comentario decía que era frágil: entrando por «localhost» se timbraba contra el
  PAC de pruebas sin avisar. Ahora es un interruptor explícito.
- La conexión con el PAC **no verificaba el certificado** (`verify_peer => false`).
