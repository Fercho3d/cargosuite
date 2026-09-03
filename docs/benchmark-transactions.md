# Benchmark — módulo Transactions: Yii2 vs. Laravel

Misma máquina, misma base de datos, mismos datos, mismos resultados.
Lo único distinto es cómo se ejecuta la consulta.

- **Fecha:** 2026-08-13
- **Máquina:** macOS, PHP 8.5.6, MariaDB 12.3 local
- **Datos:** base `frego` local — 18 293 transacciones, 33 919 cargos, 17 752 pagos
- **Método:** se arranca cada aplicación de verdad y se ejecuta lo mismo que hace
  la pantalla. 3 corridas del lado Yii2 (`tools/bench_legacy.php`) y 7 del
  lado Laravel (`tools/bench_laravel.php`), reabriendo la conexión entre
  corridas. Se reporta la **mediana**. No incluye el render del HTML.

## Resultado

| Pantalla | Yii2 (actual) | Laravel (nuevo) | Mejora |
|---|---:|---:|---:|
| Facturas — 100 filas | 9 690 ms | **14.6 ms** | **664×** |
| Costos — 100 filas | 23 013 ms | **11.7 ms** | **1 967×** |
| Todas — 100 filas | 30 937 ms | **10.6 ms** | **2 918×** |
| Transacciones de un booking | 2 942 ms | **11.7 ms** | **251×** |
| Costos + filtro "sin pagar" | 23 079 ms | **497.6 ms** | **46×** |
| Totales de todo el filtro | (no existía) | 145.4 ms | — |

Además, la pantalla de Facturas del sistema original corre **cuatro veces** la
misma consulta (el grid más los tres pases del profit por booking): a los 9.7 s
del grid hay que sumarle **20.5 s** más, o sea unos **30 segundos** para abrir la
pantalla. Esa parte se resuelve en la Etapa 6.

## En el servidor de producción (2026-08-14)

Misma medición, ahora sobre el servidor real: AWS, 2 vCPU, 3.8 GB de RAM,
**MySQL 8.0.46** y la base `sistema_frego` (18 408 transacciones, 34 101 cargos).
Yii2 corre en PHP 8.2 y el módulo nuevo en PHP 8.4-FPM, en el mismo equipo y contra
la misma base.

| Pantalla | Yii2 (puerto 80) | Laravel (puerto 9000) | Mejora |
|---|---:|---:|---:|
| Facturas — 100 filas | 15 116 ms | **158.9 ms** | **95×** |
| Costos — 100 filas | 19 138 ms | **176.5 ms** | **108×** |
| Todas — 100 filas | 22 953 ms | **196.3 ms** | **117×** |
| Transacciones de un booking | 5 412 ms | **23.3 ms** | **232×** |
| Costos + filtro "sin pagar" | 18 987 ms | **2 718.6 ms** | **7×** |
| Totales de todo el filtro | (no existía) | 732.8 ms | — |

La pantalla de Facturas completa en el sistema actual son **40 segundos**: 15.1 s del
grid más 24.8 s de los tres pases del profit por booking.

Del lado de Yii2 se corrió **una sola vez** cada pantalla, a propósito, para no
cargar el servidor que está en uso; del lado de Laravel, 7 corridas y mediana.

Dos observaciones honestas de esta medición:

- Todo es entre 5 y 15 veces más lento que en la máquina local, en ambos sistemas.
  Es lo esperado: 2 vCPU contra una portátil.
- **El filtro "sin pagar" se degrada más de lo esperado en MySQL 8** (2.7 s aquí
  contra 0.5 s en MariaDB local). Es el único caso que no puede acotarse a los IDs
  de la página, porque el filtro depende de los agregados. MySQL 8 materializa las
  tablas derivadas con otra estrategia que MariaDB. Sigue siendo 7× mejor que el
  original, pero es el punto que vale la pena atacar después.

## De dónde sale la diferencia

Todo está en el plan de ejecución; la aritmética es idéntica (lo prueban las
4 293 442 comparaciones de `QueryParityTest`).

| # | Cambio | Efecto |
|---|---|---|
| 1 | **Un solo recorrido de `charge`.** El original define tres tablas derivadas (IVA 16 %, IVA 0 % y no deducible) y las vuelve a definir dentro de la subconsulta de pagos: **seis** recorridos de 33 919 filas. Ahora es uno, con `SUM(CASE WHEN …)` por cubeta. | −5 recorridos completos |
| 2 | **Agregar solo lo que se muestra.** Primero se resuelven los IDs de la página con los filtros indexables; después se agregan **esos** IDs. El original agrega la tabla entera aunque enseñe 20 filas. | de 18 293 filas a 100 |
| 3 | **`exchange` por índice.** El `ON` del original lleva un `OR` que anula el índice único y obliga a un barrido de 1 850 filas con *join buffer* por cada fila. Ahora la unión es por las dos columnas del índice y la moneda base se resuelve con un CASE. | quita el barrido anidado |
| 4 | **Un solo pase de totales.** El original calcula `sum()` recorriendo el conjunto otra vez; ahora se envuelve la consulta ya construida. | −1 consulta monstruo |

## Sobre agregar índices: se midió y **no** conviene

La sospecha inicial era que faltaban índices —en particular
`payments_by_transaction` solo tiene `(request_id, transc_id)`, así que buscar por
`transc_id` a secas no puede usarlo. Se probó agregando
`payments_by_transaction(transc_id)` y se midió A/B con 7 corridas:

| Pantalla | Sin índice | Con `idx_pbt_transc` |
|---|---:|---:|
| Facturas | **14.6 ms** | 22.3 ms |
| Costos | **11.7 ms** | 24.1 ms |
| Todas | **10.6 ms** | 25.5 ms |
| Un booking | 11.7 ms | **2.6 ms** |
| Costos + "sin pagar" | **497.6 ms** | 584.8 ms |

Solo mejora la pantalla de un booking (4.5×, de 11.7 a 2.6 ms) y empeora todas
las demás alrededor del doble: al haber índice, el optimizador cambia de plan y
elige uno peor para el resto. Como 11.7 ms ya es más que suficiente, **no se
agregó ningún índice**: la base quedó exactamente como estaba.

La ganancia vino de la forma de la consulta, no de índices nuevos. Se deja
anotado por si en producción, con más datos, conviene volver a medirlo.

## Cómo reproducirlo

```bash
php tools/bench_legacy.php 3
```

```bash
php artisan tinker --execute="require 'tools/bench_laravel.php';"
```

El tope de tiempo también está como prueba automática, para que una regresión se
note sola:

```bash
vendor/bin/phpunit --group performance
```
