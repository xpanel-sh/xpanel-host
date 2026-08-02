# Registro de cambios

Los cambios importantes de XPanel Host se documentarán aquí. El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y las versiones estables seguirán [Versionado Semántico](https://semver.org/lang/es/).

## [Sin publicar]

### Añadido

- Subcarpeta pública opcional por sitio (`public_path`, ej. `public` para Laravel): la raíz del proyecto sigue siendo el límite del gestor de archivos, SSH/SFTP y la terminal real, mientras nginx/Apache/OpenLiteSpeed, el pool PHP-FPM, el desafío ACME de Certbot y las páginas de error personalizadas sirven únicamente desde esa subcarpeta cuando está configurada.
- IP dedicada opcional por dominio de correo (al estilo del `mailips` de cPanel/Exim, adaptado a Postfix): además de la IP/hostname compartidos del servidor, se puede dar de alta una IP ya asignada al servidor con su propio PTR/HELO y asignarla a uno o varios dominios desde "Correos" — Postfix enruta el correo saliente de esos dominios por esa IP mediante `sender_dependent_default_transport_maps`, sin afectar a los dominios que siguen en modo compartido. La guía de registros DNS por dominio ahora refleja la IP/PTR dedicada cuando aplica.
- Recuperación local de la contraseña del propietario mediante `php artisan xpanel:admin-password --generate`.
- Instalación sin variables de entorno, acceso inicial por `IP:80`, propietario automático y resumen final de credenciales.
- Ajustes para verificar y cambiar el dominio del panel, volver al acceso por IP e instalar SSL posteriormente.
- Gestión de sitios PHP y estáticos, subdominios y raíces aisladas.
- Nginx como motor inicial y soporte instalable para Apache y OpenLiteSpeed por sitio.
- Certificados Let's Encrypt, bases MariaDB y gestor de archivos.
- Correo con Postfix, Dovecot, OpenDKIM, Roundcube y asistencia de registros DNS.
- Propietarios, colaboradores, roles y permisos.
- Instalador, actualizador y operación mediante XPanel CLI.
- Backups manuales y programados de archivos y bases MariaDB, con retención, descarga y restauración protegida por un punto de seguridad previo.
- Registro de actividad por sitio sin almacenar contraseñas ni contenido de formularios.
- Límites PHP por sitio para PHP-FPM y OpenLiteSpeed, junto con una vista segura de información del runtime.
- Tareas Cron administradas por sitio, instaladas en `/etc/cron.d` con usuario de servicio sin privilegios root y log independiente.
- Reinicio controlado de los servicios asociados a un sitio desde su panel.
- Dominios aparcados compartidos entre los tres motores, incluidos como nombres SAN al reemitir certificados locales.
- Redirecciones exactas o por prefijo en el gateway público y páginas HTML propias para errores 403/404/500/502/503.
- Reparación confinada de propietarios y permisos del document root, sin seguir symlinks ni cruzar sistemas de archivos.
- Analítica reciente sin tracking, calculada desde un log público separado por sitio y leído de forma confinada.
- Purga segura de cachés conocidas, listado de carpetas configurable, protección Hotlink y reglas IP/CIDR en el gateway.
- Despliegues manuales desde repositorios Git HTTPS públicos y autenticación Basic para directorios seleccionados.
- phpMyAdmin con autenticación por cookies, root bloqueado y servidor MariaDB fijo en loopback.
- Acceso MariaDB remoto por base e IPv4 exacta, con identidades limitadas y allowlist nftables persistente.
- Identidad Unix estable por sitio aplicada a PHP-FPM, Cron, Git, restauraciones, ACME y reparación de propietarios.
- SFTP confinado por sitio, FTPS opcional con TLS y SSH por llaves públicas sin forwarding.
- Escaneos ClamAV con historial de hallazgos y cuarentena recuperable fuera del document root.
- Instalación WordPress mediante WP-CLI verificado, base exclusiva, backup previo y rollback ante fallos.
- Migración ZIP/TAR.GZ y SQL.GZ con límites anti-bomba, base nueva, historial y adaptación automática de WordPress.
- PageSpeed Insights móvil/escritorio e historial de diagnóstico técnico del sitio con pruebas locales reales.
- Editor DNS Cloudflare para A/AAAA/CNAME/MX/TXT y control CDN con proxy y purga de caché.
- XMail funcional junto a Roundcube: autenticación propia por buzón, carpetas, lectura, envío SMTP autenticado, adjuntos y acciones IMAP.

### Cambiado

- El inicio muestra KPIs, recursos, sitios y actividad real del servidor; correo y XMail tienen una jerarquía visual más clara, ajustes comparte el contenedor principal y la cabecera permite navegar sitios y subdominios por separado.

### Corregido

- Las operaciones de sitios y SSL ya no interrumpen su propia respuesta HTTP al recargar PHP-FPM; la recarga se ejecuta de forma diferida.
- La sincronización de sitios repara dominios principales ausentes en el portafolio de Dominios.
- El editor iKode puede crear, guardar, mover y subir archivos mediante ACL confinadas al document root de cada sitio.
- WordPress verifica el núcleo oficial antes de configurarlo, funciona sin terminal interactivo e instala después el idioma seleccionado.
- Las vistas de Ajustes comparten navegación y estructura visual.
- iKode crea archivos vacíos mediante su operación dedicada y respeta la carpeta actual tanto desde la barra como desde el menú contextual.
- Los hosts HTTPS sin certificado propio se rechazan y nunca heredan el contenido ni el certificado del primer sitio de Nginx.
- Los subdominios usan raíces independientes por FQDN; la sincronización migra el formato anterior anidado sin sobrescribir destinos.
- Un fallo auxiliar de instalación de la CLI ya no impide mostrar la URL y el estado final del panel.
- El helper privilegiado se versiona como ejecutable para que el instalador no ensucie el árbol Git ni bloquee `git pull`/`xpanel update`.
- Primera instalación completamente no interactiva por `IP:80`, sin solicitar dominio/correo ni intentar certificados del panel o webmail.
- Terminal real por sitio en iKode (`Avanzado → Acceso SSH → Terminal real desde el navegador`), opcional y apagada por defecto (`XPANEL_TERMINAL_ENABLED`). Un agente Go sin privilegios de root (`xpanel-terminal-agent`) hace de puente entre un token opaco de un solo uso y una sesión SSH real; una orden forzada de sshd consume el token en Laravel y verifica la identidad Unix antes de abrir la shell, por lo que ni la llave de servicio ni el agente pueden autorizar otro sitio por sí solos.
- La terminal real (SSH por llave propia o terminal web) queda enjaulada con `ChrootDirectory` de sshd: cada sesión solo alcanza el document root propio de ese sitio o subdominio — nada de ningún otro sitio del servidor es visible ni accesible desde ahí, cada identidad Unix queda completamente independiente. Los binarios del sistema montados dentro de la jaula quedan de solo lectura, y el prompt muestra el dominio en vez del usuario críptico del sistema.
- `xpanel update` recompila y reinicia el agente de terminal de forma atómica, migra las instalaciones antiguas sin conservar la clave HMAC compartida y vuelve a aplicar las restricciones SSH de cada sitio.
- Las IP dedicadas de correo se limitan a IPv4 realmente asignadas al servidor. Los cambios de Postfix respaldan y restauran `main.cf`, `master.cf` y los mapas si la validación o recarga falla; al eliminar un dominio también se retira su transporte saliente.

### Corregido

- El gestor de archivos (iKode) admite subidas hasta 2GB, igual que "Migrar sitio web", en vez de un tope artificial de 20MB — Nginx y PHP-FPM del panel ya estaban configurados para esa capacidad.
- Extraer un ZIP ahora pregunta antes de reemplazar archivos existentes con el mismo nombre, en vez de sobrescribirlos en silencio.
- Los errores de validación de rutas AJAX (gestor de archivos y otras) devolvían una página HTML en vez de JSON porque la detección de "el cliente quiere JSON" comparaba contra rutas `api/*` que no existen en esta app; ahora usa `wantsJson()`.
- El script que desmonta la jaula de la terminal ya no usa `findmnt -R --target`: cuando la carpeta de la jaula no es en sí misma un punto de montaje (nunca lo es), esa combinación resuelve al montaje raíz `/` y lista *todo* el árbol de montajes del sistema como si fueran parte de la jaula, incluido `/proc`. Ahora se filtra por coincidencia literal de prefijo en Bash, que no puede expandirse más allá de la ruta de la jaula.
- Reactivar la jaula de un sitio (por ejemplo tras cambiar su document root) ya no falla si un intento anterior quedó a medio montar: systemd solo ejecuta `ExecStop` al detener una unidad activa, nunca como limpieza automática de un `ExecStart` fallido, así que los montajes huérfanos se desmontan explícitamente con el mismo script ya reforzado antes de cada (re)inicio.
- Eliminar un archivo o carpeta desde el gestor de archivos ya no reporta éxito cuando el borrado falla en el sistema de archivos; ahora la operación devuelve un error explícito en vez de dejar el elemento en su sitio en silencio.
- El script que desmonta la jaula ya no enumera montajes con `findmnt`: en un servidor cuya tabla de montajes creció mucho (por ejemplo tras varios intentos de montaje fallidos que apilan bind mounts sobre el mismo destino, algo que `mount --bind` permite sin error), la resolución de cada entrada que hace `findmnt` puede tardar minutos y saturar los workers de PHP-FPM del panel en cada sincronización. Ahora se lee directamente `/proc/self/mountinfo`, sin pasar por `findmnt`.
- Dovecot rechazaba la sesión IMAP justo después de un login válido ("Plugin quota must be loaded also") porque el bloque `protocol imap` cargaba `imap_quota` sin el plugin base `quota` en el mismo protocolo; XMail interpretaba ese corte de conexión como credenciales incorrectas. Ahora `protocol imap` carga ambos plugins.
- XMail ya no usa STARTTLS (puerto 143) para conectarse a Dovecot: la librería IMAP de PHP corta la conexión durante el handshake STARTTLS con cierta frecuencia (un problema conocido de esa librería), reportando el corte como credenciales incorrectas. Ahora usa SSL directo (puerto 993), mucho más estable con esa librería.
- El instalador de phpMyAdmin angostaba `/etc/xpanel-host` a `root:www-data 0750` cada vez que se ejecutaba, un directorio compartido que otros servicios (la autenticación por `passwd-file` de Dovecot, entre otros) necesitan poder atravesar como sus propios usuarios sin privilegios. Rompía por completo el login de correo (Roundcube y XMail) hasta corregirlo a mano. Ahora solo se restringe el archivo del secreto de phpMyAdmin, no el directorio compartido.
- Instalación obligatoria y comprobación de la CLI global durante el despliegue de Host.
- Instalación inicial con sólo el dominio del panel; el correo ACME y el DNS de webmail quedan opcionales y no bloquean el proceso.
- Inicio de OpenDKIM en Ubuntu y Debian mediante un PID y directorio de ejecución compatibles con su unidad systemd.
- Validación temprana del correo ACME para impedir instalaciones con valores de ejemplo sin reemplazar.
- Navegación del menú de Sitios hacia las pantallas funcionales de archivos, backups, bases, actividad, PHP y Cron.
- Estados dinámicos de SSL y backups en el resumen; las acciones aún no implementadas ya no se presentan como enlaces activos.
- Conservación del estado SSL activo cuando una reemisión falla y soporte de certificados para sitios OpenLiteSpeed.
- Bloqueo de eliminación directa de dominios vinculados para evitar vhosts, alias o certificados inconsistentes.

### Seguridad

- Confinamiento del gestor de archivos al espacio administrado.
- Validación de acciones privilegiadas y backends internos limitados a loopback.
- Cada terminal SSH utiliza la identidad Unix exclusiva del sitio, llaves públicas y forwarding desactivado.
- MySQL remoto rechaza comodines, CIDR y hostnames; las contraseñas viajan por entrada estándar y no se persisten en Host.
- Las contraseñas SFTP/FTPS no se guardan y SSH interactivo rechaza autenticación por contraseña.
- La cuarentena de malware sólo acepta hallazgos persistidos, vuelve a validar su ruta real y no sigue symlinks.
- XMail usa una cookie restringida a `/xmail`, cifra la credencial temporal, limita intentos por IP y buzón y nunca hereda la sesión administrativa.
- XMail usa una cookie restringida a `/xmail`, cifra la credencial temporal, limita intentos por IP y buzón y nunca hereda la sesión administrativa.
- Las contraseñas del instalador WordPress se entregan por entrada estándar y no se persisten en Host ni aparecen en argumentos de procesos.
- Los importadores rechazan rutas externas, enlaces y tipos especiales; el SQL usa una identidad limitada y las cargas temporales se eliminan al finalizar.
- Los tokens DNS se verifican, cifran con `APP_KEY`, se ocultan del modelo y no permiten operar fuera del dominio del sitio.

[Sin publicar]: https://github.com/xpanel-sh/xpanel-host/commits/main
