# Despliegue en producción — FregoCargo (Laravel)

Convive con el Frego de Yii2 **en el mismo servidor**, en otro puerto y con otro
PHP. El sistema en operación no se modificó.

- **Servidor:** `ssh frego` → ubuntu@52.12.171.198, Ubuntu 24.04, 2 vCPU / 3.8 GB
- **Ruta:** `/var/www/html/frego-laravel`
- **URL:** http://52.12.171.198:9000
- **Fecha del primer despliegue:** 2026-08-14

## Cómo quedó repartido el servidor

| Puerto | Aplicación | PHP | Ruta |
|---|---|---|---|
| 80 | Frego (Yii2) — **en operación** | 8.2 vía mod_php | `/var/www/html/frego` |
| 8080 | Portal proveedores/clientes (Yii2) | 8.2 vía mod_php | (ver vhost `portalfrego`) |
| **9000** | **FregoCargo (Laravel)** | **8.4 vía PHP-FPM** | `/var/www/html/frego-laravel` |

El vhost nuevo (`/etc/apache2/sites-available/frego-laravel.conf`) es el único que
manda los `.php` a PHP-FPM 8.4; los demás siguen con mod_php 8.2 sin enterarse.
El `php` de la línea de comandos quedó fijado a 8.2 a propósito
(`update-alternatives --set php /usr/bin/php8.2`) para no cambiarle el intérprete
a nada existente. Para este proyecto se usa `php8.4` explícitamente.

## Qué se tocó del servidor

1. **Paquetes instalados** (repo ondrej, que ya estaba configurado):
   `php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl
   php8.4-zip php8.4-intl php8.4-bcmath`.
   También quedó instalado php8.3 de un primer intento; no lo usa nadie y se puede
   purgar con `sudo apt-get purge 'php8.3-*'`.
2. **Módulos de Apache habilitados:** `proxy`, `proxy_fcgi`.
3. **`Listen 9000`** — declarado dentro del vhost nuevo, así que desactivar el sitio
   también cierra el puerto.
4. **Base de datos `sistema_frego`** — solo cambios aditivos:
   - `users`: + `remember_token`, `two_factor_secret`, `two_factor_recovery_codes`,
     `two_factor_confirmed_at`
   - tablas nuevas: `migrations`, `password_reset_tokens`, `permissions`, `roles`,
     `model_has_permissions`, `model_has_roles`, `role_has_permissions`,
     `personal_access_tokens`

   Nada se borró ni se modificó. Yii2 no lee esas columnas y sigue igual.

## Respaldo

Antes de migrar se guardó `/home/ubuntu/frego-backups/sistema_frego_20260814_180141.sql.gz`
(6.4 MB, integridad verificada con `gzip -t`).

Para restaurar:

```bash
ssh frego 'gunzip -c /home/ubuntu/frego-backups/sistema_frego_20260814_180141.sql.gz | mysql -u fregodb -p'
```

## Requisito pendiente: abrir el puerto en AWS

Apache ya escucha en el 9000 y responde bien desde dentro del servidor, pero el
**security group de AWS bloquea el puerto desde fuera**. Hay que agregar una regla
de entrada TCP 9000. Conviene restringirla a las IP desde las que se va a ver el
demo en vez de abrirla a `0.0.0.0/0`, porque esto va sin HTTPS.

Para comprobar que ya quedó:

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://52.12.171.198:9000/
```

Debe responder `302` (redirección al login).

## Volver a desplegar una versión nueva

Empaquetar en local y lanzar el script; **no hacerlo a mano**. Los tres primeros
despliegues manuales tumbaron el sitio, siempre por lo mismo: permisos.

```bash
npm run build && COPYFILE_DISABLE=1 tar czf /tmp/frego-laravel.tar.gz --exclude='.git' --exclude='node_modules' --exclude='vendor' --exclude='.env' --exclude='./storage' --exclude='./bootstrap/cache' --exclude='public/hot' . && scp /tmp/frego-laravel.tar.gz frego:/tmp/
```

```bash
ssh frego 'bash -s' < tools/deploy-remote.sh
```

El script hace el orden correcto y verifica al final. Las reglas que codifica, por
si alguna vez hay que repetirlas a mano:

- **Todo lo que escribe en `storage/` o `bootstrap/cache` corre como `www-data`**
  (`sudo -u www-data php8.4 artisan …`). Un `artisan` corrido como `ubuntu` deja
  archivos que el proceso web no puede reescribir; el sitio aguanta hasta que
  expira un TTL y entonces da 500 sin haber cambiado nada. Fue la causa de la
  segunda caída.
- **`storage/` y `bootstrap/cache` no van en el paquete.** Son del proceso web y
  `tar`, corriendo como `ubuntu`, falla al intentar ajustarles el modo.
- **No barrer `storage` con `chown`/`chmod` con el sitio en uso.** Deja a `www-data`
  unos segundos sin escribir sesiones y a quien esté navegando le sale *"la sesión
  ha expirado"*.
- **`cache:clear` en cada despliegue**, antes de recachear configuración y rutas.
- Los directorios de escritura llevan **setgid (2775)** para que lo que se cree
  dentro herede el grupo.

- **El servidor no tiene Node**, así que `public/build` se compila en local y viaja
  dentro del paquete.
- `composer.lock` está resuelto con Symfony 8, que exige **PHP ≥ 8.4.1**. Por eso el
  servidor lleva 8.4 y no 8.3.
- El `.env` no viaja en el paquete: vive solo en el servidor, en modo 640 y con las
  credenciales tomadas de `frego/config/db.php`.

## Cómo dar marcha atrás

```bash
ssh frego 'sudo a2dissite frego-laravel && sudo systemctl reload apache2'
```

Con eso se apaga el sitio y se libera el puerto 9000; Frego ni se entera. Para
quitarlo del todo, borrar `/var/www/html/frego-laravel` y, si se quiere, revertir
las columnas aditivas (aunque son inofensivas).

## Cómo se entra

No hay usuarios nuevos: la aplicación usa la **misma tabla `users`** que Frego, y las
contraseñas bcrypt de Yii2 son compatibles. Se entra con la cuenta de siempre.

Ojo con el esquema heredado: en producción la columna `username` guarda el correo y
`email` guarda a veces el nombre. El login acepta cualquiera de las dos, así que
sirve escribir el correo.

**Solo administradores** (rol 10 y 20), igual que el `AccessControl` del
`TransactionController` de Yii2. Al desplegar por primera vez esto faltaba y
cualquier cuenta con sesión llegaba a la facturación; se corrigió el mismo día con
`EnsureUserIsAdmin` y sus pruebas. Hoy, de las 79 cuentas activas, 3 tienen acceso.

## Estado del módulo desplegado

Solo lo terminado hasta la etapa 4: **los listados de transacciones son de lectura**
(facturas, costos, todas y las de un booking, con filtros, orden y paginación).
No hay alta/edición/borrado ni timbrado de CFDI, así que **este despliegue no puede
alterar datos de operación**; lo único que escribe es el login (sesión y 2FA).

## Aparte: MySQL está expuesto a internet

Se detectó de paso que `mysqld` escucha en `0.0.0.0:3306` y existe el usuario
`fregodb@%` (desde cualquier host). Si el security group deja pasar el 3306, la base
de producción es alcanzable desde fuera. No se tocó nada de esto porque queda fuera
del encargo, pero conviene revisarlo.

## El planificador (correos automáticos)

Los avisos de tareas atrasadas salen de `routes/console.php` una vez al día. Para
que corran, el servidor necesita **una** línea de cron que despierte a Laravel
cada minuto:

```cron
* * * * * cd /var/www/html/frego-laravel && php8.4 artisan schedule:run >> /dev/null 2>&1
```

Sin esa línea no se manda nada. Para ver qué hay programado y a qué hora:

```bash
php8.4 artisan schedule:list
```

La hora de los avisos es la de la operación (`America/Mexico_City`), aunque el
servidor corra en UTC. Para probar sin mandar correo:

```bash
php8.4 artisan operacion:avisos-continuidad vencido --simular
```
