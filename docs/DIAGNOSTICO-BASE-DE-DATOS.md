# Diagnóstico de la base de datos

Medido contra la base real (52 tablas), no supuesto. Cada punto trae la consulta
o el número que lo demuestra.

Estado: ⬜ pendiente · ✅ hecho

---

## Graves

### 1. ⬜ La llave primaria de `transaction` incluye el nombre de un archivo

```
transaction -> PRIMARY (transc_id, pdf_attach)
transaction -> transc_id (transc_id)      <- índice suelto para compensar
```

La identidad de una factura depende de cómo se llame su PDF adjunto. Por eso
`pdf_attach` es NOT NULL y hay un índice redundante. InnoDB agrupa por la llave
primaria, así que **cada índice secundario arrastra el varchar**, y ninguna tabla
puede declarar una foránea limpia contra `transaction`.

**Arreglo:** `PRIMARY KEY (transc_id)` y quitar el índice `transc_id`.
**Riesgo:** alto, reconstruye la tabla (17,880 filas). Ventana de mantenimiento.

### 2. ⬜ Ocho tablas sin llave primaria

`payments_by_transaction` (17,932 filas, el corazón de los pagos),
`check_list_history` (94,858), `containers_history` (16,176),
`booking_continuity_history` (15,731), `booking_history` (9,790),
`pay_form`, `pay_method`, `invoice_use`.

`payments_by_transaction` ya tiene el índice único `(request_id, transc_id)`:
basta con promoverlo a llave primaria. Los catálogos del SAT, su `code`.

### 3. ⬜ `client` es MyISAM

Sin transacciones ni recuperación ante caída, y con bloqueo de tabla entera.
Es una tabla central. Explica por qué el `rollBack` del sistema viejo no revierte
nada. **Arreglo:** `ENGINE=InnoDB`.

### 4. ✅ Tres sistemas de identidad en paralelo

`client`, `provider` y `carrier` tienen cada uno `password`, `auth_key` y
`password_reset_token`; `client` y `provider` además `verification_code`.
Credenciales vivas: **5 clientes, 1 proveedor, 1 naviera**. Ninguna pasa por
Fortify ni por el 2FA. Es el punto 6 del roadmap del producto.

### 5. ⬜ Los teléfonos son `int(11)` y ya perdieron datos

`client.phone` y `provider.phone`. De 18 clientes con teléfono:
**11 tienen menos de 10 dígitos** (se comió el cero inicial) y **7 están al borde
del límite del entero**. Con lada internacional no caben.

**Arreglo:** `varchar(30)`. Los que ya se truncaron no se recuperan; hay que
volver a capturarlos.

## Medias

### 6. ⬜ 37 tablas en `utf8mb3`

Y cuatro cotejamientos conviviendo: `utf8mb3_unicode_ci` (40 tablas),
`utf8mb4_unicode_ci` (9, las de Laravel), `utf8mb4_uca1400_ai_ci` (`company`),
`utf8mb3_uca1400_ai_ci` (`users`). `utf8mb3` está obsoleto en MySQL 8 y no admite
emoji ni buena parte del chino y el japonés — relevante para comercio con Asia.

**Arreglo:** todo a `utf8mb4_unicode_ci`. Ojo con los índices sobre varchar
largos al convertir (crece el tamaño máximo de la llave).

### 7. ⬜ Precisiones de dinero incoherentes

`decimal(16,4)`, `(18,4)`, `(18,2)`, `(16,2)` y `(11,4)` mezcladas. En la misma
tabla: `payment_request.amount` es `(18,2)` y `payment_request.total_to_pay`
`(18,4)`. Los tipos de cambio: `exchange.exchange_value` `(11,4)` y
`transaction.custom_tc` `(10,4)`.

**Arreglo:** un solo tipo para importes —`decimal(18,4)`— y otro para tipos de
cambio —`decimal(12,6)`—. **Antes hay que comprobar que ningún importe se
redondee al convertir**, y que las pruebas de paridad sigan cuadrando al centavo.

### 8. ⬜ Datos duplicados en `booking`

Guarda a la vez el texto y el identificador de lo mismo: `dicharge_port` +
`dicharge_port_id`, `carrier` + `carrier_id`, `pick_up_place` +
`pick_up_place_id`, `final_destination` + `final_destination_id`.

**Ya se separaron:** de 1,529 bookings con las dos columnas llenas, **4 no
coinciden** con el catálogo. **Arreglo:** el identificador manda, el texto se
queda solo donde haga falta como valor histórico.

### 9. ⬜ `custom_tc` significa dos cosas

En `transaction` es un `decimal(10,4)` (un valor); en `payment_request`, un
`tinyint` (una bandera). Mismo nombre, distinto significado.

### 10. ⬜ Banderas que no son `tinyint(1)`

`account.default` y `charge.prepaid` son `int(11)`.

---

## Cómo aplicarlo

Todo con **migraciones versionadas**, en dos tandas:

1. **Sin tocar datos** (motor, cotejamiento, llaves primarias, tipos de bandera).
   Reversibles y con la aplicación arriba, salvo la del punto 1.
2. **Con conversión de datos** (teléfonos, precisiones, columnas duplicadas).
   Cada una con su comprobación antes y después.

⚠️ La base de producción es la del sistema anterior, que **sigue en uso desde
Yii2**: cualquier cambio de esquema tiene que seguir sirviéndole. Los puntos 1,
5 y 8 hay que consultarlos con el cliente antes de aplicarlos allí.
