# Migración del módulo Transactions — Yii2 (Frego) → Laravel (FregoLaravel)

> Objetivo: portar **completo** el módulo de transacciones y demostrar, con números,
> que la versión Laravel carga mucho más rápido que la Yii2, devolviendo **exactamente
> los mismos resultados**.
>
> Regla dura: **no se toca `/opt/homebrew/var/www/frego`** (sistema original en producción).
> Todo el trabajo vive en `/opt/homebrew/var/www/FregoLaravel`.
> La base de datos `frego` es **compartida**: solo lecturas y, a lo sumo, índices aditivos.

---

## 1. Radiografía del módulo original

### Archivos fuente (Yii2)

| Archivo | Líneas | Rol |
|---|---|---|
| `controllers/TransactionController.php` | 1117 | 29 acciones |
| `models/Transaction.php` | 1103 | AR + CFDI + pagos + adjuntos |
| `models/TransactionSearch.php` | 525 | **el motor de consulta** (todo el peso) |
| `models/TransactionReport.php` | 174 | reporte global |
| `models/TransactionsBy{Booking,Customer,Vendor}.php` | 540 | reportes agrupados |
| `models/Charge*.php` | 436 | conceptos de la transacción |
| `views/transaction/*.php` | 4 000+ | 22 vistas (kartik GridView + pjax) |

### Pantallas / acciones a portar

| Grupo | Acciones Yii2 | Prioridad |
|---|---|---|
| **Listados** | `index` (por booking), `all`, `invoice`, `bill` | **P0** — es lo que se demuestra |
| **Reportes** | `global-report`, `report-by-booking`, `report-by-customer`, `report-by-vendor`, `report-general` + 5 detalles AJAX | P1 |
| **CRUD** | `create`, `update`, `update-ajax`, `update-type`, `delete`, `view`, `modify-date` | P1 |
| **Cargos** | `charge-detail` + `ChargeController` | P1 |
| **Facturación** | `timbrar`, `cancel`, `cancel-invoice`, `reenviar`, `custom-pdf` | P2 (toca el PAC) |
| **Pagos** | `pay`, `set-amount-to-pay` | P2 |
| **Archivos** | `pdf-file`, `xml-file` | P2 |

### Volumen real (BD local `frego`)

```
transaction              18 293
charge                   33 919
payments_by_transaction  17 752
payment_request           4 438
booking                   5 284
exchange                  1 853   (account 1/MXN = 1 sola fila, valor 1.0000)
charge_type                  30
```

---

## 2. Por qué el original es lento (diagnóstico)

`TransactionSearch::search()` arma **una sola consulta monstruo**. Sus problemas medibles:

1. **Seis recorridos completos de `charge`.** Define 3 tablas derivadas
   (`VAT16`, `VAT0`, `nonDec`), cada una agregando las 33 919 filas de `charge`…
   y luego las vuelve a definir **otra vez** dentro de la subconsulta `payments`.
   MariaDB materializa cada derivada íntegra, *antes* de aplicar cualquier filtro.
2. **Los filtros no bajan a las derivadas.** Aunque pidas 20 filas de un booking,
   se agregan las 33 919 líneas de cargo del sistema entero.
3. **JOIN de `exchange` con `OR`** →
   `(acct = exchange.account AND tran_date = date_exchange) OR (acct = exchange.account AND account.default = 1)`.
   Un `OR` entre columnas distintas anula el índice `uq_date`; se resuelve por barrido.
4. **`payments_by_transaction` no tiene índice por `transc_id` solo**
   (su único índice es `(request_id, transc_id)`) → el JOIN de pagos barre 17 752 filas.
5. **Filtro "Paid/Partial/Unpaid" en `HAVING`** sobre expresiones de agregado: obliga a
   materializar *todo* el resultado antes de descartar filas.
6. **Varias pantallas corren con `pagination = false`** (`index`, y los 3 pases del
   profit summary de `invoice`): traen el conjunto completo a PHP.
7. **`actionInvoice` dispara 4 veces la consulta monstruo** (1 del grid + 3 de
   `buildProfitSummary`), cada una con sus 6 barridos de `charge`.
8. **`$_GET['debug']` se lee sin `isset`** y `GridView` de kartik arrastra jQuery +
   pjax + select2 + daterangepicker en cada carga.

---

## 3. Estrategia de optimización (mismo resultado, otra ejecución)

| # | Cambio | Efecto esperado |
|---|---|---|
| O1 | **Un solo agregado de `charge`** con `SUM(CASE WHEN …)` condicional en lugar de 3 derivadas | 6 barridos → 1 |
| O2 | **Consulta en dos fases**: (a) IDs paginados con filtros indexables, (b) agregados *solo* de esos IDs | agregación acotada a la página (20-100 filas) en vez de 18 293 |
| O3 | **JOIN de `exchange` sin `OR`**: `LEFT JOIN exchange ON account = … AND date_exchange = tran_date` + rama explícita para la cuenta default | usa `uq_date`; se preserva la semántica (verificado: MXN tiene 1 sola fila = 1.0000) |
| O4 | **Índices aditivos**: `payments_by_transaction(transc_id)`, `transaction(tran_date)`, `transaction(tran_type, cancelled)`, `transaction(booking, tran_type)` | elimina los barridos de 17 752 / 18 293 filas |
| O5 | **Un solo pase para el profit summary** de `invoice` (hoy son 3 consultas monstruo) | −75 % del trabajo de esa pantalla |
| O6 | **Caché de catálogos** (accounts, companies, charge_types: 4 + 2 + 30 filas) | quita N consultas por render |
| O7 | **Sin jQuery/pjax/kartik**: Livewire + `wire:navigate` + Tailwind ya compilado | payload de red mucho menor y sin recarga completa |

**Ninguna optimización cambia la aritmética.** Los `IFNULL`, el signo (`* -1`), el
`CASE` de `negativeCondition`, el TC ponderado por monto (`paid_exchange_value`) y los
`NULL` heredados (p. ej. las 2 transacciones en EUR sin tipo de cambio → `NULL`) se
replican **al pie de la letra**. Eso es justo lo que verifican las pruebas de paridad.

---

## 4. Arquitectura en Laravel

```
app/
  Models/Frego/            Transaction, Charge, ChargeType, Exchange, Account,
                           Company, Booking, Client, Provider, PaymentRequest,
                           PaymentByTransaction
  Queries/
    TransactionQuery.php   ← motor: equivalente optimizado de TransactionSearch
    TransactionFilters.php ← DTO de filtros (reemplaza las 25 props públicas)
    ProfitSummary.php      ← equivalente de buildProfitSummary
  Livewire/Transactions/
    InvoiceTable.php  BillTable.php  AllTable.php  BookingTable.php
    TransactionForm.php  ChargeTable.php
  Support/Legacy/
    LegacySqlBuilder.php   ← SOLO en tests: reproduce el SQL de Yii2 literal
resources/views/livewire/transactions/*.blade.php
tests/
  Feature/Transactions/ParityTest.php      ← Laravel vs SQL legado, fila por fila
  Feature/Transactions/PerformanceTest.php ← mide y registra ambas
  Unit/Transactions/*                      ← aritmética, filtros, formatos
```

**SPA**: Livewire 4 (ya instalado) con layout persistente + `wire:navigate`.
Navegación entre pantallas y cambios de filtro/orden/página **sin recarga completa**;
solo viaja el fragmento del grid. No hace falta API separada ni bundle SPA.

---

## 5. Etapas

| Etapa | Contenido | Estado | Entregable |
|---|---|---|---|
| **0. Baseline** | Capturar el SQL real de Yii2 y medirlo | ✅ | `docs/baseline-transactions.md`, `tools/bench_legacy.php` |
| **1. Modelos** | 11 modelos Eloquent sobre las tablas existentes | ✅ | `app/Models/Frego/` |
| **2. Motor** | `TransactionQuery` + `TransactionFilters` con O1–O5 | ✅ | `app/Queries/` |
| **3. Paridad + tests** | Legado vs. nuevo, celda por celda, 31 escenarios | ✅ | `tests/Feature/Transactions/`, `tests/Unit/Transactions/` |
| **4. SPA listados** | `invoice`, `bill`, `all` e `index` por booking en Livewire | ✅ | `app/Livewire/Transactions/`, `/transacciones` |
| **8. Benchmark** | Medición lado a lado | ✅ | `docs/benchmark-transactions.md` |
| **5. CRUD + cargos** | Alta/edición/borrado de transacción y cargos, validaciones portadas | ⏳ | — |
| **6. Reportes** | Profit por booking + los 5 reportes agrupados y sus detalles | ⏳ | — |
| **7. Acciones CFDI** | Timbrado, cancelación, reenvío, pagos, descarga PDF/XML | ⏳ | — |

La etapa 8 se adelantó porque es lo que había que demostrar. Cada etapa se entrega
con sus pruebas en verde antes de pasar a la siguiente.

### Qué queda y qué falta decidir

- **Etapa 5 (CRUD).** Hay que portar `validateVendor` (un proveedor no puede
  repetirse en el mismo booking), `validateAmountToPay` y la numeración automática
  de factura (`F-` + consecutivo). Necesita una BD de pruebas propia, porque estas
  sí escriben.
- **Etapa 6 (reportes).** El profit por booking hoy corre la consulta monstruo tres
  veces; se resuelve en un pase. Ojo con las columnas no deterministas descritas
  arriba en las pruebas de paridad.
- **Etapa 7 (CFDI).** Depende del componente SOAP de Facturación Moderna y de dónde
  van a vivir las credenciales del PAC en Laravel. Es la única parte que no se puede
  probar sin decidir eso antes.

### Comandos

```bash
vendor/bin/phpunit
```

```bash
vendor/bin/phpunit --group parity
```

```bash
vendor/bin/phpunit --group performance
```

---

## 6. Riesgos y decisiones abiertas

- **Índices en BD compartida.** Son aditivos (no rompen Yii2, de hecho lo aceleran),
  pero se aplican en local primero y en producción solo con visto bueno explícito.
- **Números `NULL` heredados.** Se conservan tal cual; el objetivo es paridad, no
  "arreglar" datos. Cualquier diferencia encontrada se documenta, no se corrige en silencio.
- **Timbrado CFDI (Etapa 7).** Depende del componente SOAP de Facturación Moderna;
  se porta al final y con las credenciales de pruebas.
- **`frego_test`.** Las pruebas de escritura necesitan BD propia; las de paridad y
  rendimiento leen la BD real local (`frego`) porque el valor está en el volumen real.
