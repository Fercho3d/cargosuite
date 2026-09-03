# Baseline — módulo Transactions en Yii2 (Frego)

Medición del sistema **original** antes de migrar, para tener contra qué comparar.

- **Fecha:** 2026-08-13
- **Máquina:** macOS, PHP 8.5.6, MariaDB local
- **Base de datos:** `frego` (local, copia de producción) — 18 293 transacciones, 33 919 cargos
- **Cómo se midió:** `tools/bench_legacy.php`, que arranca la aplicación Yii2 real
  y ejecuta **exactamente** lo que hace cada acción de `TransactionController`
  (incluidos los pases extra del profit summary de `invoice`).
  3 corridas por pantalla, conexión reabierta entre corridas. Solo tiempo de consulta
  + hidratación en PHP; **no** incluye el render de las vistas kartik/pjax.

## Resultados

| Pantalla | Acción Yii2 | Filas | Mín | Mediana | Máx |
|---|---|---:|---:|---:|---:|
| Invoice — grid | `actionInvoice` (grid) | 100 | 9 598 ms | **9 690 ms** | 9 869 ms |
| Invoice — profit summary | `buildProfitSummary` (3 pases) | 21 670 | 18 904 ms | **20 462 ms** | 23 722 ms |
| **Invoice — total de la pantalla** | | | | **≈ 30 s** | |
| Bill — grid | `actionBill` | 100 | 22 952 ms | **23 013 ms** | 23 415 ms |
| All — grid | `actionAll` | 100 | 30 862 ms | **30 937 ms** | 31 007 ms |
| Index de un booking | `actionIndex` (booking 976) | 51 | 2 939 ms | **2 942 ms** | 2 977 ms |
| Bill + filtro "Unpaid" | `actionBill` con `paid=0` | 100 | 22 993 ms | **23 079 ms** | 23 322 ms |

Traer **100 filas** de facturas cuesta entre 9 y 31 segundos. La pantalla de Invoice
completa ronda los **30 segundos** porque corre la misma consulta monstruo cuatro veces.

## EXPLAIN de la consulta de Invoice

```
1  PRIMARY          transaction              ALL   ...  18069  Using where; Using temporary; Using filesort
1  PRIMARY          exchange                 ALL   uq_date → NULL  1850  Using where; Using join buffer (flat, BNL join)
5  DERIVED          payments_by_transaction  ALL   NULL   17918  Using temporary; Using filesort
5  DERIVED          exchange_req             ALL   uq_date → NULL  1850  Using where; Using join buffer (BNL)
6  DERIVED          charge                   ALL   ...  33577  Using where; Using temporary; Using filesort
7  DERIVED          charge                   ALL   ...  33577  Using where; Using temporary; Using filesort
8  DERIVED          charge                   ALL   ...  33577  Using where; Using temporary; Using filesort
2  LATERAL DERIVED  charge                   ref   fk_tran  (1)
3  LATERAL DERIVED  charge                   ref   fk_tran  (1)
4  LATERAL DERIVED  charge                   ref   fk_tran  (1)
```

Lo que confirma el plan:

1. **`transaction` se barre completo** (18 069 filas) más `temporary` + `filesort`,
   aunque solo se muestren 100 filas.
2. **`exchange` se resuelve con barrido completo y BNL join** (1 850 filas, sin índice):
   el `OR` del `ON` — `(cuenta = X AND fecha = Y) OR (cuenta = X AND account.default = 1)` —
   inutiliza el índice `uq_date`.
3. **Los tres agregados de `charge` dentro de la subconsulta `payments` (derived 6/7/8)
   barren las 33 577 filas de `charge` cada uno.** MariaDB sí logra convertir en
   `LATERAL DERIVED` los tres de arriba (2/3/4), pero **no** los de adentro.
4. **`payments_by_transaction` se barre completo** (17 918 filas) porque su único índice
   es `(request_id, transc_id)` y aquí se busca por `transc_id` solo.
5. El filtro Paid/Partial/Unpaid vive en `HAVING`, así que no descarta nada antes de
   materializar todo.

## Objetivo de la migración

Reproducir **exactamente** estos números de negocio (misma aritmética, mismos `NULL`,
mismos signos) ejecutando:

- **un** agregado de `charge` en vez de seis,
- la agregación acotada a los IDs de la página, no a la tabla entera,
- el join de `exchange` por índice,
- el profit summary en un solo pase,
- y sin recarga completa de página (Livewire + `wire:navigate`).

Ver `PLAN-MODULO-TRANSACTIONS.md`.
