# Rastreo GPS de la flota

CargoSuite recibe la posición de cada unidad por una API y la dibuja en
**Flota → Mapa de la flota** (filtros por unidad, estado y viaje en curso; se
refresca cada 30 s). Solo existe con la modalidad terrestre.

## Cómo llegan las posiciones

No hay un protocolo estándar de GPS: cada fabricante tiene el suyo. Por eso los
equipos no le hablan a CargoSuite directamente sino a un **servidor Traccar**
(libre, entiende más de 200 protocolos), que reenvía cada posición ya
traducida:

```
GPS del camión ──(protocolo de su marca, TCP)──▶ Traccar ──(JSON por HTTPS)──▶ CargoSuite
App del celular ──(OsmAnd, HTTPS)────────────────────────────────────────────▶ CargoSuite
```

Una flota puede mezclar marcas: cada protocolo escucha en su propio puerto de
Traccar. En **Catálogos → Dispositivos GPS** se da de alta cada equipo con su
identificador (IMEI), su unidad y su marca/protocolo.

| Marca (lo más común en México) | Protocolo en Traccar | Puerto de fábrica |
|---|---|---|
| Teltonika (FMB920, FMC130, FMB640) | teltonika | 5027 |
| Queclink (GV300, GV350) | gl200 | 5004 |
| Concox / Jimi (GT06, JM-VL03) | gt06 | 5023 |
| Suntech | suntech | 5011 |
| Ruptela | ruptela | 5046 |
| Meitrack | meitrack | 5020 |

Verificar los puertos contra la tabla de dispositivos de la versión de Traccar
instalada.

## Configuración

En el `.env` de CargoSuite:

```dotenv
GPS_TOKEN="una-clave-larga-y-aleatoria"   # vacía = la API no acepta nada
GPS_SERVIDOR=gps.midominio.com             # a donde se configuran los equipos
GPS_SIN_SENAL_MINUTOS=15
GPS_RETENCION_DIAS=90
```

En Traccar (`traccar.xml`), reenviar cada posición en JSON:

```xml
<entry key='forward.enable'>true</entry>
<entry key='forward.json'>true</entry>
<entry key='forward.url'>https://cargo.midominio.com/api/gps/posiciones?token=LA-CLAVE</entry>
```

App **Traccar Client** en el celular del operador (sin comprar equipo): como
dirección del servidor, `https://cargo.midominio.com/api/gps/osmand/LA-CLAVE`,
y como identificador el mismo que se capture en el catálogo.

⚠️ Los GPS hablan TCP, no HTTP: el subdominio de Traccar va **sin el proxy de
Cloudflare** (nube gris) y con los puertos abiertos en el firewall del servidor.

## Ruta del viaje

En el mapa, **Ver ruta del viaje** (en la ficha de la unidad o en la lista)
dibuja:

- **La ruta planeada** (azul): por carretera, del origen al destino pasando por
  el punto de carga y el de entrega, los que tengan coordenadas en su catálogo.
  Se calcula una vez por viaje y se guarda en `ruta_viaje`; se recalcula sola si
  cambian las paradas.
- **El recorrido real** (verde): las posiciones del GPS desde el día de la carga.

Servicio de rutas (`.env`):

```dotenv
ORS_API_KEY=...            # OpenRouteService, perfil de camión de carga (driving-hgv)
RUTAS_PROVEEDOR=ors        # ors | osrm | ninguno
OSRM_URL=https://...       # OSRM propio, si se usa osrm
```

Sin `ORS_API_KEY` se usa OSRM; el servidor público de OSRM es **solo para
pruebas** (no se permite uso comercial intensivo). La clave de OpenRouteService
es gratuita con límite diario (openrouteservice.org); para casetas y
restricciones de camión precisas están HERE, TomTom o Google Routes, de pago. Si
el servicio no responde, la ruta sale como línea recta marcada «aproximada» y
no se guarda.

## API

- `POST /api/gps/posiciones` — JSON de Traccar: `device.uniqueId`,
  `position.latitude`, `position.longitude`, `position.speed` (nudos),
  `position.course`, `position.fixTime`.
- `GET|POST /api/gps/osmand[/{token}]` — `id`, `lat`, `lon`, `speed` (nudos),
  `bearing`, `timestamp` (segundos o ISO).

La clave va en la ruta, en `?token=` o en el encabezado `X-GPS-Token`. Un equipo
no dado de alta responde `202` y no se guarda (con un error, Traccar lo
reintentaría sin fin). La velocidad se guarda en km/h y la hora en la zona del
sistema.

## Mantenimiento

- `gps:depura` (diario, 03:15) borra el historial más viejo que la retención.
- `gps:simula-demo` (cada minuto, solo con `MARCA_DEMO=true`) mueve las unidades
  de la demostración sobre la ruta de su viaje, para que no salgan «sin señal».
