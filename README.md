# CargoSuite

Sistema de **operación, facturación y cobranza para agentes de carga**: embarques,
contenedores, lista de continuidad, facturación CFDI 4.0 con timbrado, costos por
proveedor, solicitudes de pago, reportes de utilidad y portal para clientes y
proveedores.

Laravel 13 · PHP 8.4+ · Livewire · Tailwind v4 · MySQL/MariaDB.

## Marca configurable

El sistema **no lleva ningún nombre escrito en el código**. Nombre, logotipo
(de letra o de imagen, con versión clara y oscura), color de acento, favicon,
ficha de los documentos impresos y direcciones de correo salen de
`config/marca.php` y se cambian por `.env`, sin recompilar los estilos.

👉 **[docs/MARCA-BLANCA.md](docs/MARCA-BLANCA.md)** — instalarlo para otro cliente.

## Arrancar en local

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan db:seed --class=DemoSeeder     # operación de ejemplo completa
```

Queda en http://cargosuite.loc (vhost de Apache) o con `php artisan serve`.
Cuentas de la demostración: `demo.admin`, `demo.facturacion`, `demo.operaciones`,
`demo.cliente` y `demo.proveedor`, contraseña `demo1234`.

## Tareas programadas

En el servidor, el cron de Laravel una vez por minuto:

```bash
* * * * * cd /ruta/al/sistema && php artisan schedule:run >> /dev/null 2>&1
```

| Comando | Cuándo | Qué hace |
|---|---|---|
| `exchange:diario` | lunes a viernes, 07:30 | trae el tipo de cambio del dólar de Banxico |
| `operacion:avisos-continuidad aviso` | diario, 07:00 | avisa las tareas del booking que aún tienen tiempo |
| `operacion:avisos-continuidad vencido` | diario, 07:05 | avisa las que ya se pasaron de fecha |
| `cfdi:revisar-cancelaciones` | diario, 08:00 | pregunta al SAT en qué quedaron las cancelaciones solicitadas |

`cfdi:revisar-cancelaciones` recorre las solicitudes pendientes de
`cfdi_cancelacion`, consulta el servicio público del SAT y marca como canceladas
las que el SAT ya da por canceladas; espera un segundo entre consultas
(`--pausa=0` para no esperar). Hace falta porque el PAC avisa una sola vez, al
recibir la solicitud: lo que pase después solo lo sabe el SAT. Ver
`docs/CORREOS-Y-PDF.md`.

Los dos avisos solo se programan con `MARCA_AVISOS_CORREO=true` en el `.env`
(`marca.correo.avisos_por_correo`). `php artisan schedule:list` enseña lo que quedó activo.

## Pruebas

```bash
php artisan test                      # corrida normal
php artisan test --group=demo         # las pantallas contra la base de ejemplo
php artisan test --group=parity       # comparación con el sistema anterior
php artisan test --group=performance  # vigila los tiempos de consulta
./vendor/bin/pint                     # estilo
```

Los tres últimos grupos quedan fuera de la corrida normal porque necesitan MySQL
con datos.

## Documentación

| Archivo | De qué trata |
|---|---|
| [MARCA-BLANCA.md](docs/MARCA-BLANCA.md) | instalarlo para otro cliente |
| [DEPLOY-PRODUCCION.md](docs/DEPLOY-PRODUCCION.md) | despliegue |
| [CORREOS-Y-PDF.md](docs/CORREOS-Y-PDF.md) | correos y documentos impresos |
| [GENERACION-AUTOMATICA.md](docs/GENERACION-AUTOMATICA.md) | propuesta de factura y costos del booking |
| [HALLAZGOS-SISTEMA-VIEJO.md](docs/HALLAZGOS-SISTEMA-VIEJO.md) | defectos encontrados al migrar |
| [PLAN-MODULO-TRANSACTIONS.md](docs/PLAN-MODULO-TRANSACTIONS.md) y los `benchmark-*` | motor de consulta y sus mediciones |
