<h1 align="center">XPanel Host</h1>

<p align="center">
  Panel de hosting libre y autohospedable para administrar sitios, dominios, SSL,<br>
  bases de datos, archivos y correo desde tu propio servidor Linux.
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-Apache--2.0-blue" alt="Licencia Apache-2.0"></a>
  <img src="https://img.shields.io/badge/PHP-8.3%2B-777BB4" alt="PHP 8.3 o posterior">
  <img src="https://img.shields.io/badge/Laravel-13-FF2D20" alt="Laravel 13">
  <img src="https://img.shields.io/badge/status-desarrollo_activo-f59e0b" alt="Estado: desarrollo activo">
</p>

<p align="center">
  <a href="#inicio-rápido">Inicio rápido</a> ·
  <a href="#capacidades">Capacidades</a> ·
  <a href="#correo">Correo</a> ·
  <a href="#actualizaciones">Actualizaciones</a> ·
  <a href="CONTRIBUTING.md">Contribuir</a> ·
  <a href="SECURITY.md">Seguridad</a>
</p>

---

> [!WARNING]
> XPanel Host está en desarrollo activo y todavía no tiene una versión estable. Pruébalo primero en un VDS limpio y conserva respaldos externos antes de utilizarlo con datos importantes.

## Qué es XPanel Host

XPanel Host funciona de manera independiente en un VPS, VDS o servidor dedicado. También puede instalarse dentro de una MicroVM creada por [XPanel Core](https://github.com/xpanel-sh/xpanel-core), pero Core no es un requisito.

Host no vende planes ni crea revendedores. Cada instalación pertenece a un propietario, que puede invitar colaboradores y utilizar los recursos del servidor o los asignados por Core.

```text
Servidor Linux o MicroVM
        │
        ├── Nginx público :80/:443
        ├── PHP-FPM / Apache / OpenLiteSpeed
        ├── MariaDB
        ├── Postfix + Dovecot + OpenDKIM
        ├── Roundcube + XMail
        └── XPanel Host + xpanel CLI
```

## Capacidades

| Área | Incluido actualmente |
| --- | --- |
| **Sitios** | PHP y estáticos, document root aislado, subdominios, dominios aparcados, redirecciones y páginas de error |
| **Motores web** | Nginx inicial; Apache y OpenLiteSpeed instalables bajo demanda |
| **SSL** | Let's Encrypt con Certbot, renovación y estado del certificado |
| **Bases de datos** | Bases y usuarios MariaDB aislados por sitio, phpMyAdmin y accesos remotos limitados por IPv4 |
| **Correo** | Postfix, Dovecot, Maildir, Roundcube y XMail, SPF, DKIM, DMARC y verificación DNS |
| **Archivos** | Gestor de archivos confinado al espacio administrado |
| **Backups** | Copias manuales o programadas, retención, descarga y restauración segura de archivos y bases |
| **PHP y tareas** | Límites PHP por sitio, resumen seguro del runtime y tareas Cron administradas sin ejecución como root |
| **Tráfico y seguridad** | Analítica desde logs, caché, listado de carpetas, protección Hotlink y reglas IPv4/IPv6 por sitio |
| **Acceso** | Propietario, equipo, roles, permisos, SFTP confinado, FTPS opcional y SSH por llaves |
| **Operación** | Instalador idempotente, CLI compartida, actualizaciones y smoke tests |
| **Despliegue** | Standalone o dentro de una MicroVM administrada por Core |

## Servidor recomendado

Para la primera instalación se recomienda un VDS limpio con:

- Debian 12/13 o Ubuntu Server 22.04/24.04.
- Acceso `root` o `sudo`.
- Una IP pública.
- Al menos 2 GB de RAM; utiliza más memoria si activas Apache, OpenLiteSpeed o varias cuentas PHP.
- Puertos 22, 80 y 443 permitidos.
- Puertos 25, 587 y 993 si utilizarás correo.
- El puerto HTTP 80 permitido para el acceso inicial por IP y la verificación posterior del dominio.

No publiques los puertos internos 8082, 8083, 7080 u 8088. Host limita los backends opcionales a loopback.

## Inicio rápido

Conéctate a un VDS limpio y ejecuta:

```bash
ssh root@IP_DEL_SERVIDOR
git clone https://github.com/xpanel-sh/xpanel-host.git /opt/xpanel-host
cd /opt/xpanel-host
bash install.sh
```

El instalador no hace preguntas: configura el acceso inicial por `IP:80`, instala dependencias, servicios, migraciones y la CLI global, crea el primer administrador y muestra la URL, correo y contraseña al finalizar. No solicita dominio, Gmail, correo ACME, SSL ni variables `XPANEL_*`.

### Acceso inicial y dominio del panel

Antes de ejecutar este comando, crea el registro DNS:

```text
example.com A IP_DEL_SERVIDOR
```

El panel queda disponible inmediatamente en:

```text
http://IP_DEL_SERVIDOR:80/login
```

Después entra en **Ajustes → Acceso al panel**, escribe el dominio o subdominio, verifica su registro A y aplica el cambio. Host redirige a la nueva dirección; desde la misma pantalla puedes instalar el certificado SSL. El DNS y SSL de correo siguen siendo posteriores e independientes.

## Primer acceso

El instalador crea automáticamente el primer propietario y muestra sus credenciales una sola vez. Guárdalas antes de cerrar la terminal. La ruta `/setup` queda bloqueada y el acceso normal continúa por `/login`.

Si la terminal se cerró antes de mostrar las credenciales, genera una contraseña nueva como `root`:

```bash
cd /opt/xpanel-host
php artisan xpanel:admin-password --generate
```

## Primer sitio

1. Apunta el registro `A` del dominio a la IP del servidor.
2. Entra en **Websites → Nuevo sitio**.
3. Escribe el dominio, tipo de sitio, versión PHP y document root.
4. Elige uno de los motores instalados.
5. Crea el sitio y copia los archivos al document root o usa el file manager.
6. Abre **Seguridad → SSL** y emite el certificado.
7. Crea una base desde **Bases de datos → Administración** si la aplicación la necesita.
8. Ajusta memoria, subidas y tiempo de ejecución desde **Avanzado → Configuración PHP**.
9. Si la aplicación necesita procesos periódicos, créalos desde **Avanzado → Cron Jobs**.

### Alias, redirecciones y errores

- **Dominios → Dominios aparcados** añade alias que comparten archivos y configuración con el dominio principal. Cada alias necesita su propio registro `A`/`AAAA`; al reemitir SSL, Certbot lo incorpora al certificado SAN del sitio.
- **Dominios → Redirecciones** crea reglas exactas o por prefijo con códigos 301, 302, 307 o 308. Se aplican en el Nginx público y por ello funcionan con cualquiera de los motores internos.
- **Sitio web → Páginas de error** administra HTML estático para 403, 404, 500, 502 y 503. Los archivos quedan dentro de `.xpanel-errors` y no ejecutan PHP.
- **Avanzado → Corregir propietarios** restaura el usuario/grupo de servicio sin seguir enlaces simbólicos, cruzar montajes ni convertir archivos privados en públicos.
- **Análisis** resume solicitudes, IPs únicas, transferencia, errores y rutas desde las últimas 10 000 entradas del log del gateway, sin insertar rastreadores en las páginas.
- **Avanzado → Caché** limpia únicamente ubicaciones conocidas de Laravel, WordPress y cachés convencionales; no elimina sesiones ni archivos subidos.
- **Avanzado → Protección Hotlink** protege extensiones elegidas y permite referentes adicionales.
- **Avanzado → Administrador de IP** trabaja en modo blocklist o allowlist con IPv4, IPv6 y CIDR. ACME permanece permitido para no romper renovaciones.
- **Avanzado → Git** despliega ramas de repositorios HTTPS públicos de GitHub, GitLab o Bitbucket. Antes de reemplazar archivos crea un backup `pre_deploy`; publica desde una caché privada, rechaza symlinks y conserva `.env`, ACME, páginas de error y sesiones.
- **Avanzado → Directorios protegidos** aplica HTTP Basic antes del backend; las contraseñas se almacenan únicamente como hashes bcrypt legibles por Nginx.

En una MicroVM administrada por Core, cada alias también debe registrarse como dominio de entrada en Core/Traefik. Host configura el servicio interno, pero no modifica automáticamente el enrutamiento del servidor padre.

### Módulos visibles que siguen en preparación

El menú conserva el diseño futuro, pero marca como **En preparación** las funciones que aún no tienen operación de servidor completa: constructor web. Sus botones de mutación permanecen desactivados; no se presentan datos de ejemplo como si fueran reales.

Cada sitio recibe una identidad Unix estable y distinta. PHP-FPM, Cron, despliegues Git, restauraciones y reparaciones de propiedad utilizan ese usuario; Nginx/Apache reciben acceso mediante el grupo del sitio.

**Archivos → Cuentas FTP** habilita SFTP confinado en un jail cuya carpeta `/site` enlaza únicamente el document root. FTPS explícito es opcional, utiliza vsftpd, una allowlist de usuarios y los puertos 21/40000-40100; FTP anónimo o sin TLS permanece desactivado. La contraseña Linux se entrega al helper por entrada estándar y Host sólo registra cuándo se rotó.

**Avanzado → Acceso SSH** habilita terminal exclusivamente después de registrar una llave Ed25519 o RSA. La terminal no acepta contraseña y bloquea forwarding TCP, X11, túneles y gateway ports. Al permitir terminal, SFTP utiliza el mismo usuario y el aislamiento entre sitios depende de los grupos Unix exclusivos y permisos `0750`; sin terminal, SFTP usa además `ChrootDirectory`.

### Escáner de malware

**Seguridad → Escáner de malware** ejecuta ClamAV sobre el document root real, registra cuántos archivos revisó, conserva los últimos resultados y muestra la firma exacta de cada detección. El escaneo no sigue enlaces simbólicos ni cruza otros sistemas de archivos. Las definiciones se mantienen mediante `clamav-freshclam`.

La acción de cuarentena sólo puede aplicarse a un hallazgo del mismo sitio. El helper vuelve a resolver y confinar la ruta antes de mover el archivo a `/var/lib/xpanel-host/quarantine/<dominio>/<escaneo>/`; no lo elimina ni lo deja accesible desde la web. Los falsos positivos deben revisarse antes de borrar o restaurar manualmente un archivo.

### WordPress e instalador automático

**Sitio web → Instalador automático** muestra únicamente provisionadores disponibles; inicialmente ofrece WordPress. El flujo descarga WordPress con [WP-CLI oficial](https://make.wordpress.org/cli/handbook/guides/installing/), verifica los checksums del core, crea una base y usuario MariaDB exclusivos y configura el administrador e idioma elegidos.

Antes de publicar la instalación se crea un backup `pre_install`. WordPress se prepara en una carpeta temporal y sólo después de instalarse correctamente reemplaza el contenido web, conservando ACME y las páginas de error de Host. Si falla después del backup, Host intenta restaurarlo y marca cualquier limpieza manual pendiente. Las contraseñas de WordPress y MariaDB viajan por entrada estándar: la primera queda bajo control de WordPress y la segunda sólo en `wp-config.php`; ninguna se guarda en la base de XPanel Host.

El instalador y cada actualización descargan el PHAR estable de WP-CLI junto con su SHA-512 publicado y rechazan el archivo si no coincide. La operación usa la versión PHP seleccionada para el sitio y su identidad Unix independiente.

### Migración de sitios

**Sitio web → Migrar sitio web** acepta archivos `.zip`, `.tar.gz` o `.tgz` de hasta 2 GiB comprimidos y 4 GiB extraídos. Rechaza rutas absolutas, `..`, enlaces, archivos especiales, más de 200 000 entradas y archivos que salgan del staging privado. Si el paquete sólo contiene una carpeta superior, importa automáticamente su contenido. ACME, páginas de error y sesiones administradas permanecen fuera del reemplazo.

El respaldo de base debe ser `.sql.gz`. Host crea una base y usuario nuevos, importa con esa identidad limitada y nunca pisa una base existente. Para migraciones genéricas, el usuario coloca después esas credenciales en su aplicación. Para WordPress, Host actualiza `wp-config.php`, comprueba que la base contiene una instalación, sustituye URLs mediante WP-CLI respetando datos serializados, ajusta `home`/`siteurl` y valida checksums antes de publicar.

Cada migración conserva historial, cantidad de archivos, bytes, base creada y token del backup `pre_migration`. Ante un error se intenta restaurar ese backup y retirar la base nueva; si el servidor impide completar el rollback, ambos recursos se conservan y el panel marca la limpieza pendiente. Los paquetes subidos se eliminan del staging al terminar.

El panel configura Nginx y su PHP-FPM para cargas grandes. Cuando Host vive dentro de una MicroVM administrada por Core, el ingress exterior de Core/Traefik también debe permitir el tamaño y tiempo de carga elegidos; Host no puede ampliar por sí solo el límite del servidor padre.

### PageSpeed y diagnóstico

**Rendimiento → PageSpeed** consulta la [API oficial PageSpeed Insights v5](https://developers.google.com/speed/docs/insights/v5/get-started) con la URL pública derivada del sitio; el usuario no puede proporcionar destinos arbitrarios. Conserva mediciones móvil/escritorio, puntuaciones Lighthouse, FCP, LCP, TBT, CLS, Speed Index y las oportunidades principales. Los fallos o límites de cuota quedan registrados como fallos, nunca como puntuaciones ficticias. La API admite consultas sin clave; un propietario con permiso de servidor puede guardar, reemplazar o retirar `PAGESPEED_API_KEY` directamente desde esta vista sin exponer su valor actual.

**Rendimiento → Diagnóstico del sitio** ejecuta comprobaciones deterministas dentro del Host: document root, propietario Unix, gateway Nginx, backend elegido, PHP-FPM/LSPHP, respuesta HTTP y HTTPS local, resolución DNS, uso de disco, estado SSL registrado, último malware y existencia de backup. Cada resultado queda como correcto, aviso o fallo con historial. No envía archivos ni logs a servicios de IA.

### DNS y CDN mediante Cloudflare

La primera integración de **Avanzado → Editor DNS** es Cloudflare. El usuario crea un [API Token limitado](https://developers.cloudflare.com/fundamentals/api/get-started/create-token/) a la zona con `Zone DNS Edit`, copia el Zone ID y Host verifica ambos contra la API. El token se cifra mediante el cast `encrypted` de Laravel y `APP_KEY`; nunca se vuelve a mostrar ni se guarda como texto legible. No se admite Global API Key.

El editor consulta registros reales y permite crear, actualizar o eliminar A, AAAA, CNAME, MX y TXT. Aunque el token tenga acceso a toda la zona, Host filtra y vuelve a verificar que cada nombre pertenezca al dominio del sitio o a uno de sus subdominios; esto incluye nombres de correo como `_dmarc` y `selector._domainkey`. Los identificadores enviados manualmente no permiten modificar registros de otro dominio ni tipos estructurales como NS.

**Rendimiento → CDN** reutiliza la conexión para cambiar `proxied` en los registros A/AAAA/CNAME del dominio exacto, siguiendo la [API DNS de Cloudflare](https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/list/). La purga completa usa el endpoint oficial de [purga de caché](https://developers.cloudflare.com/api/go/resources/cache/methods/purge/) y requiere el permiso adicional `Cache Purge`; afecta a toda la zona, por lo que el panel lo indica antes de ejecutarla. Desconectar elimina el token cifrado de Host, pero no modifica registros remotos.

### phpMyAdmin y MySQL remoto

El instalador añade phpMyAdmin desde los paquetes mantenidos por Debian/Ubuntu y lo publica en `/phpmyadmin` bajo el mismo hostname del panel. Utiliza autenticación `cookie`, conecta únicamente a MariaDB local, bloquea el acceso root y no permite elegir servidores arbitrarios. Cada usuario entra con las credenciales creadas en **Bases de datos → Administración**.

**Bases de datos → MySQL remoto** autoriza una IPv4 exacta para una base concreta. Host crea una identidad `usuario@IP`, conserva los privilegios dentro de esa base y reconstruye una allowlist nftables para el puerto 3306. No utiliza `%`, rangos ni root remoto. Al retirar la última autorización, MariaDB vuelve a escuchar sólo en `127.0.0.1`. Si el proveedor del VDS tiene un firewall exterior, la misma IP debe permitirse también allí.

### Subdominios

Desde **Sitio → Dominios → Subdominios** escribe sólo la etiqueta (`blog`, `tienda`, `api`). Host crea un sitio hijo como `blog.example.com`, su document root y su configuración web. El hijo hereda inicialmente el motor, tipo y versión PHP del sitio principal, pero conserva administración propia para archivos, bases de datos y SSL.

Para publicarlo debes crear fuera de Host un registro DNS `A`/`AAAA` hacia la IP del servidor, o un wildcard como `*.example.com`. Cuando resuelva, entra en la ficha del subdominio y emite su certificado en **Seguridad → SSL**. Crear el subdominio en Host no registra automáticamente DNS en Cloudflare ni en otro proveedor; esa integración requerirá credenciales/API del proveedor en una fase posterior.

Nginx es la única opción inicial. Para agregar otro motor abre **Ajustes → Motores web**:

- Apache se instala como backend en `127.0.0.1:8082`.
- OpenLiteSpeed instala LSPHP/LSAPI y utiliza `127.0.0.1:8083`.
- El motor aparece en el formulario del sitio solamente después de superar la instalación y validación.

Los archivos del sitio no se eliminan al cambiar de motor. Las reglas `.htaccess` funcionan con Apache/OpenLiteSpeed; Nginx necesita reglas equivalentes.

## Correo

Host configura:

```text
SMTP entrante       25
SMTP submission     587 + STARTTLS
IMAP seguro         993 + TLS
Maildir             /var/mail/vhosts/<dominio>/<usuario>/Maildir
```

El instalador despliega Roundcube en `XPANEL_WEBMAIL_HOSTNAME` y XMail dentro del panel en `/xmail`; ambos se conectan por loopback al mismo Dovecot/Postfix. Cada persona entra con su dirección completa y la contraseña creada en Host. Ninguno mantiene buzones alternativos: todos los mensajes continúan en el Maildir de la cuenta.

Antes de instalar crea:

```text
mail.example.com A IP_DEL_SERVIDOR
```

La instalación inicial no exige que `mail.example.com` ya exista. Cuando el usuario active correo, Host muestra los registros A, MX, SPF, DKIM y DMARC que debe crear. Después de apuntar `mail.example.com` a la IP, emite su certificado y lo aplica al webmail, IMAP y SMTP con:

```bash
cd /opt/xpanel-host
sudo bash scripts/enable-webmail-ssl.sh
```

Accesos disponibles:

```text
Navegador           https://mail.example.com
IMAP                 mail.example.com:993 SSL/TLS
SMTP submission      mail.example.com:587 STARTTLS
Usuario              direccion-completa@example.com
```

Para correo público debes configurar externamente:

- `A` o `AAAA` para `mail.example.com`;
- `MX` del dominio hacia `mail.example.com`;
- PTR/rDNS de la IP;
- SPF, DKIM y DMARC;
- permiso del proveedor para tráfico por el puerto 25.

Host muestra estos valores exactos en **Correos → DNS del correo** para cada dominio. Desde ese modal puedes copiarlos y comprobar en DNS público los registros A, MX, SPF, DKIM, DMARC, PTR y el certificado TLS. En Cloudflare, `mail.example.com` debe permanecer en **DNS only** (nube gris); el PTR se solicita al proveedor del VDS y no se crea en Cloudflare.

Cada dominio recibe al sincronizar su primera cuenta una clave RSA DKIM independiente. La clave privada queda fuera del directorio público y OpenDKIM firma el correo saliente; el panel solo enseña la clave pública que debe copiarse en `xpanel._domainkey`.

Si el servidor tenía una versión anterior sin OpenDKIM, actualiza el repositorio y ejecuta una vez el instalador idempotente para añadir el servicio y su conexión con Postfix:

```bash
cd /opt/xpanel-host
sudo bash install.sh
```

No crees `mail.example.com` como un sitio normal: el instalador reserva ese hostname para Roundcube. XMail está disponible en `/xmail`, usa autenticación propia por buzón y no reutiliza la sesión administrativa. Ambos clientes trabajan sobre el mismo Maildir sin duplicar mensajes; consulta `docs/XMAIL.md`.

<a id="actualizaciones"></a>

## CLI y actualizaciones

La CLI detecta el producto desde el directorio actual:

```bash
cd /opt/xpanel-host
xpanel status
xpanel version
xpanel git
```

También puedes indicar la raíz explícitamente:

```bash
xpanel status --root=/opt/xpanel-host
```

Actualizar Host:

```bash
cd /opt/xpanel-host
xpanel update
```

`xpanel update` actualiza primero `xpanel-cli` y luego el clon de Host usando sus ramas remotas configuradas. La actualización de Host:

1. se detiene si existen cambios locales rastreados por Git;
2. crea un respaldo fechado de `.env` y de la base SQLite en `storage/app/backups/updates`;
3. activa temporalmente el modo mantenimiento;
4. instala las dependencias de producción de Composer;
5. ejecuta `npm ci` y compila los recursos con Vite;
6. aplica migraciones y reconstruye las cachés;
7. resincroniza sitios, correo y OpenDKIM;
8. actualiza Roundcube conservando sus datos;
9. vuelve a habilitar el panel incluso si una etapa falla.

Si una versión añade un servicio que todavía no existe en el servidor, el actualizador ejecuta el instalador idempotente. El instalador también proporciona Node.js 22 LTS desde los binarios oficiales y verifica su checksum antes de compilar el panel.

Los respaldos automáticos de actualización no sustituyen una copia externa de `/var/www`, `/var/mail/vhosts`, `/etc/letsencrypt` y las bases MariaDB.

Los backups por sitio se gestionan desde **Sitio → Archivos → Backups**. En producción se guardan en `/var/lib/xpanel-host/backups`, fuera del document root, con lectura restringida. La restauración exige escribir el dominio y crea primero una copia `pre_restore`. Configura además una copia externa: la retención local no protege frente a una pérdida completa del servidor.

Cuando Roundcube está habilitado, una actualización ejecutada con `sudo` también verifica/reinstala la versión fijada del webmail sin reemplazar su base SQLite, configuración, sesiones temporales ni logs persistentes.

`xpanel serve` utiliza el servidor de desarrollo de Laravel y no debe utilizarse para producción. La desinstalación automática permanece deshabilitada para evitar eliminar datos accidentalmente.

## Comprobación del servidor

Después de crear un sitio, SSL y una cuenta de correo puedes ejecutar:

```bash
cd /opt/xpanel-host
sudo XPANEL_SMOKE_DOMAIN=www.example.com \
  XPANEL_SMOKE_SITE_USER=xps... \
  XPANEL_SMOKE_MAIL_ACCOUNT=test@example.com \
  XPANEL_SMOKE_MAIL_PASSWORD='CONTRASENA_REAL' \
  bash scripts/smoke-host-services.sh
```

La prueba comprueba Nginx y los motores opcionales instalados, MariaDB, Postfix, Dovecot, HTTPS, autenticación, una entrega SMTP → LMTP y el recorrido IMAP/SMTP propio de XMail.

## Instalación dentro de XPanel Core

Core utiliza el mismo `install.sh`, pero entrega variables como:

```text
XPANEL_MANAGEMENT_MODE=core
XPANEL_CORE_URL=https://core.example.com
XPANEL_CORE_SERVICE_ID=<identificador>
XPANEL_PANEL_DOMAIN=host-cliente.example.com
XPANEL_ASSIGNED_CPU=<cantidad>
XPANEL_ASSIGNED_MEMORY_MIB=<memoria>
XPANEL_ASSIGNED_DISK_GIB=<disco>
```

Core controla la MicroVM, sus recursos, estado y acceso exterior. Host controla sitios, dominios, correo y bases dentro de la MicroVM. En este modo el TLS público pertenece a Core/Traefik y Host no ejecuta Certbot local para esa entrada.

## Desarrollo

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan test
php artisan serve --port=8081
```

El instalador web `get.xpanel.sh` se documentará cuando la distribución pública esté lista. Hasta entonces, el método soportado es Git + `install.sh`.

## Proyecto y comunidad

- Consulta los cambios en [CHANGELOG.md](CHANGELOG.md).
- Lee cómo participar en [CONTRIBUTING.md](CONTRIBUTING.md).
- Sigue el [Código de conducta](CODE_OF_CONDUCT.md).
- Para preguntas de uso consulta [SUPPORT.md](SUPPORT.md).
- Para vulnerabilidades utiliza el proceso privado de [SECURITY.md](SECURITY.md); no abras un issue público.

## Licencia

XPanel Host se distribuye bajo la [Apache License 2.0](LICENSE). Puedes usarlo, modificarlo y distribuirlo, incluso comercialmente, respetando los avisos y condiciones de la licencia. Los nombres y marcas de XPanel no se conceden mediante esta licencia.
