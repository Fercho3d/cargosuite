# Reportes de cobros y pagos: Yii2 contra Laravel

Medición sobre la **misma base y el mismo equipo**, ejecutando el SQL real que
produce `PaymentRequestSearch` de Yii2 y el que produce `PaymentRequestQuery`.

Se reproduce con:

```
php tools/bench_payments.php
```

Los escenarios son los capturados en `tests/Fixtures/legacy-payment-request-sql.json`
(ver `tools/export_legacy_payment_sql.php`), y los mismos que verifica la prueba de
paridad celda por celda.

| Escenario | Yii2 | Laravel | Veces | Filas |
|---|---:|---:|---:|---:|
| Reporte por cliente | 4 446 ms | 92 ms | 48× | 88 |
| Reporte general | 16 587 ms | 255 ms | 65× | 2 |
| Reporte por proveedor | 11 998 ms | 182 ms | 66× | 220 |
| Detalle general | 16 475 ms | 267 ms | 62× | 4 435 |
| Detalle por cliente | 4 412 ms | 86 ms | 51× | 1 754 |
| Detalle por proveedor | 12 091 ms | 181 ms | 67× | 2 681 |
| Filtro por cliente | 410 ms | 11 ms | 36× | 202 |
| Filtro por proveedor | 221 ms | 8 ms | 29× | 248 |
| Filtro por banco | 3 699 ms | 89 ms | 42× | 1 828 |
| Filtro por fechas | 1 335 ms | 23 ms | 58× | 468 |
| Solo sin pagar | 33 ms | 4 ms | 8× | 3 |
| Con fecha de revaluación | 22 014 ms | 290 ms | **76×** | 4 435 |
| Sin conversión de divisa | 16 615 ms | 253 ms | 66× | 4 438 |
| Sin inversión de signo | 16 817 ms | 266 ms | 63× | 4 438 |
| **Total** | **127 153 ms** | **2 008 ms** | **63×** | |

El peor caso —revaluar todo el histórico a una fecha— pasa de **22 segundos a 290
milisegundos**.

## De dónde sale la diferencia

1. **Un solo recorrido de `charge` en vez de tres.** El original define tres tablas
   derivadas (IVA 16 %, IVA 0 % y no deducible) y MariaDB materializa cada una
   completa, con las 33 919 líneas de cargo del sistema, *antes* de aplicar ningún
   filtro. Aquí es un `SUM(CASE WHEN …)` en una pasada.
2. **El `JOIN` de `exchange` por igualdad.** El original lo hace con
   `(cuenta = … AND fecha = …) OR (cuenta = … AND default = 1)`; ese `OR` entre
   columnas distintas anula el índice `uq_date` y obliga a barrer la tabla. Aquí las
   dos condiciones son igualdades y el índice se usa.

Ninguna de las dos toca la aritmética: los 15 escenarios devuelven **exactamente**
los mismos números, comprobado celda por celda en
`tests/Feature/Payments/PaymentRequestParityTest`.

## Un escenario que el original no puede correr

`por_solicitud` —filtrar el reporte por número de solicitud— falla en Yii2 con
`ERROR 1052: Column 'request_id' in WHERE is ambiguous`: el filtro no califica la
tabla y la columna existe tanto en `payment_request` como en
`payments_by_transaction`. En la versión de Laravel la consulta corre.
