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
        ├── Roundcube
        └── XPanel Host + xpanel CLI
```

## Capacidades

| Área | Incluido actualmente |
| --- | --- |
| **Sitios** | PHP y estáticos, document root aislado, subdominios y cambio de motor por sitio |
| **Motores web** | Nginx inicial; Apache y OpenLiteSpeed instalables bajo demanda |
| **SSL** | Let's Encrypt con Certbot, renovación y estado del certificado |
| **Bases de datos** | Bases y usuarios MariaDB aislados por sitio |
| **Correo** | Postfix, Dovecot, Maildir, Roundcube, SPF, DKIM, DMARC y verificación DNS |
| **Archivos** | Gestor de archivos confinado al espacio administrado |
| **Backups** | Copias manuales o programadas, retención, descarga y restauración segura de archivos y bases |
| **Acceso** | Propietario, equipo, roles y permisos |
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
- Un registro DNS `A` para el hostname del panel si deseas HTTPS desde la instalación.

No publiques los puertos internos 8082, 8083, 7080 u 8088. Host limita los backends opcionales a loopback.

## Inicio rápido

Primero publica estos repositorios:

```text
https://github.com/xpanel-sh/xpanel-host
https://github.com/xpanel-sh/xpanel-cli
```

Después entra al servidor:

```bash
ssh root@IP_DEL_SERVIDOR
git clone https://github.com/xpanel-sh/xpanel-host /opt/xpanel-host
cd /opt/xpanel-host
XPANEL_INSTALL_CLI=yes bash install.sh
```

El instalador descarga dependencias, configura Nginx, PHP-FPM, MariaDB, Postfix, Dovecot y Certbot, ejecuta las migraciones e instala `xpanel-cli` como `/usr/local/bin/xpanel`.

### Instalación con dominio y SSL del panel

Antes de ejecutar este comando, crea el registro DNS:

```text
panel.example.com A IP_DEL_SERVIDOR
```

Cuando DNS ya resuelva y el puerto 80 sea accesible:

```bash
cd /opt/xpanel-host
XPANEL_PANEL_DOMAIN=panel.example.com \
XPANEL_ACME_EMAIL=admin@example.com \
XPANEL_MAIL_HOSTNAME=mail.example.com \
XPANEL_WEBMAIL_HOSTNAME=mail.example.com \
XPANEL_INSTALL_CLI=yes \
bash install.sh
```

El panel quedará en:

```text
https://panel.example.com/setup
```

Si todavía no tienes DNS, omite `XPANEL_PANEL_DOMAIN` y `XPANEL_ACME_EMAIL`. Al terminar abre:

```text
http://IP_DEL_SERVIDOR/setup
```

Cuando prepares DNS, vuelve a ejecutar el instalador de forma idempotente para guardar el hostname, regenerar el vhost y emitir SSL:

```bash
cd /opt/xpanel-host
sudo env \
  XPANEL_PANEL_DOMAIN=panel.example.com \
  XPANEL_ACME_EMAIL=admin@example.com \
  XPANEL_INSTALL_CLI=yes \
  bash install.sh
```

Si el hostname ya estaba configurado y solamente faltaba emitir el certificado, utiliza:

```bash
cd /opt/xpanel-host
sudo bash scripts/enable-panel-ssl.sh admin@example.com
```

## Primer acceso

La ruta `/setup` solicita:

1. nombre del propietario;
2. correo de acceso;
3. contraseña y confirmación.

No existe una contraseña predeterminada. Después de crear el primer propietario, `/setup` se bloquea y el acceso continúa por `/login`.

## Primer sitio

1. Apunta el registro `A` del dominio a la IP del servidor.
2. Entra en **Websites → Nuevo sitio**.
3. Escribe el dominio, tipo de sitio, versión PHP y document root.
4. Elige uno de los motores instalados.
5. Crea el sitio y copia los archivos al document root o usa el file manager.
6. Abre **Seguridad → SSL** y emite el certificado.
7. Crea una base desde **Bases de datos → Administración** si la aplicación la necesita.

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

El instalador despliega Roundcube en `XPANEL_WEBMAIL_HOSTNAME` y lo conecta por loopback al mismo Dovecot/Postfix. Cada persona entra con su dirección completa y la contraseña creada en Host. Roundcube no mantiene buzones alternativos: todos los mensajes continúan en el Maildir de la cuenta.

Antes de instalar crea:

```text
mail.example.com A IP_DEL_SERVIDOR
```

Con `XPANEL_ACME_EMAIL` configurado, el instalador emite el certificado de `mail.example.com` y lo aplica tanto al webmail HTTPS como a IMAP/SMTP. Si DNS todavía no estaba listo:

```bash
cd /opt/xpanel-host
sudo bash scripts/enable-webmail-ssl.sh admin@example.com
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

No crees `mail.example.com` como un sitio normal: el instalador reserva ese hostname para Roundcube. XMail tendrá autenticación propia por buzón y no reutilizará la sesión administrativa; su contrato y migración están descritos en `docs/XMAIL.md`.

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
sudo xpanel update
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
  XPANEL_SMOKE_MAIL_ACCOUNT=test@example.com \
  XPANEL_SMOKE_MAIL_PASSWORD='CONTRASENA_REAL' \
  bash scripts/smoke-host-services.sh
```

La prueba comprueba Nginx y los motores opcionales instalados, MariaDB, Postfix, Dovecot, HTTPS, autenticación y una entrega SMTP → LMTP.

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
