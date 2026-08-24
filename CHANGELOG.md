# Registro de cambios

Los cambios importantes de XPanel Host se documentarán aquí. El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y las versiones estables seguirán [Versionado Semántico](https://semver.org/lang/es/).

## [Sin publicar]

### Cambiado

- Dashboard y resumen del servidor actualizan CPU, RAM, red, procesos e I/O sin recargar. Standalone y VM leen su propio sistema; una instancia VPS queda confinada a su slice cgroups v2 y avisa explícitamente si esa fuente no está disponible.
- Uso de recursos agrega el dominio principal y sus subdominios, recibe nuevas muestras automáticamente y ofrece desglose por sitio sin confundir estadísticas observadas con límites comerciales de la cuenta.
- El gestor de archivos del dominio principal presenta ahora su familia como carpetas hermanas con nombres completos —principal y subdominios—, excluye dominios no asociados y oculta la carpeta física `subdomains` para conservar las fronteras de identidad Unix. La vista SSL usa tarjetas compactas y separa correctamente la etiqueta del correo ACME.
- Seguridad → SSL del dominio principal centraliza ahora el estado y las acciones de todos sus subdominios, permite activar pendientes o reemitirlos en conjunto y redirige allí cualquier acceso desde la ficha de un subdominio.
- Los buzones Maildir conservan a `vmail` como propietario pero usan setgid y ACL heredables para el usuario de la cuenta y el panel; la actualización repara árboles existentes y el explorador devuelve un diagnóstico útil si una carpeta no puede leerse. En la terminal, estado y reconexión forman ahora un bloque compacto alineado al extremo derecho.
- La reparación manual de propietarios desaparece del panel: el gestor sincroniza automáticamente propietario, permisos y ACL únicamente sobre cada archivo o carpeta que crea, guarda, sube o renombra, y hace una revisión completa sólo después de descomprimir. El Maildir real migra a `mail/<dominio>/<cuenta>`, los registros públicos a `logs/<dominio>`, y los certificados públicos emitidos a `ssl/certs/<dominio>` sin exponer claves privadas.
- El estado y la reconexión de cada terminal se integran en su propia fila lateral, con truncado legible en paneles estrechos; se elimina la barra duplicada sobre la consola.
- La instalación y la actualización normalizan Node.js y npm en `/usr/local/bin` aunque Ubuntu los haya instalado en `/usr/bin`; los fallos al preparar o guardar un runtime vuelven al formulario con diagnóstico en lugar de responder con un error 500 genérico.
- Los formularios para crear y editar sitios ahora tienen desplazamiento propio, una cabecera informativa y secciones de configuración condicionadas por runtime; Node.js ya no muestra controles de PHP y fija Nginx como proxy.
- El modo de MicroVM administrada pasa de la identidad heredada `core` a `vm`, incluidas las variables `XPANEL_VM_*`, el contexto del servidor, las vistas y las validaciones SSL.

### Añadido

- Chat de agentes contenido por completo en el panel derecho de Ikode, con pestañas para conexiones cifradas de Codex/OpenAI y Claude/Anthropic, varios chats por agente, historial y ajustes internos. Cada conversación permanece separada por cuenta o sitio y el contexto se limita en el servidor; secretos, correo, SSL y dependencias voluminosas quedan fuera.
- Hogar Unix de alojamiento en `/home/<cuenta>` con estructura `public_html`, correo, logs, SSL, temporales y metadatos auxiliares `.xpanel`; el administrador general queda confinado a toda la cuenta y el administrador por dominio a un único proyecto.
- Terminal general de la cuenta, independiente de las terminales aisladas por sitio.

- Contrato de ejecución administrada por XPanel VPS para colocar pools PHP-FPM y servicios Node.js dentro de la slice systemd/cgroups de su instancia.

- Hosting de aplicaciones Node.js 22 LTS mediante servicios systemd por sitio, usuario Unix aislado, puerto loopback reservado, proxy Nginx y WebSockets; los comandos de inicio se limitan a formas seguras de `npm` y `node`.
- Modos declarativos para aplicaciones multi-tenant por ruta, wildcard de subdominios, dominios personalizados o modo híbrido.
- Certificados wildcard Let's Encrypt por DNS-01 usando la conexión Cloudflare cifrada del sitio.

- Buscador global funcional con resultados de sitios, subdominios, dominios, correo, bases de datos, equipo, ajustes y módulos; respeta permisos y admite navegación completa con teclado mediante `Ctrl/Cmd + K`.
- Chat interno del equipo en la cabecera, con conversaciones directas, grupos de varios miembros, mensajes persistentes, lectura independiente por conversación e indicador total de pendientes.
- Notificaciones persistentes para acciones administrativas importantes, fallos de backups programados y errores de certificados SSL, con estado leído/no leído.

- Configuración protegida de `PAGESPEED_API_KEY` desde la propia vista PageSpeed: la clave entra al helper por stdin, permanece oculta, actualiza la caché de configuración y restaura `.env` si la activación falla.
- Medición real por sitio con muestras cada cinco minutos: archivos e inodos del `document_root`, tamaño de sus bases MariaDB, CPU/RAM/procesos e I/O de su identidad Unix, además de solicitudes y transferencia de su access log. La vista conserva 31 días de historial y distingue estas métricas observadas de los límites globales que administra VM.
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

- Los sitios Node.js preparan automáticamente sus dependencias como el usuario Unix aislado (`npm ci` con lockfile, `npm install` sin él), ejecutan el script `build` cuando existe y evitan reinstalar o recompilar proyectos sin cambios antes de reiniciar su unidad systemd.
- iKode elimina la terminal simulada y la pestaña duplicada «Terminal real»: el diseño original de sesiones aloja ahora directamente terminales xterm.js conectadas a shells Linux con PTY. El agente Go permanece sólo como transporte sin privilegios y no interpreta comandos.
- El actualizador conserva `/var/lib/xpanel-host` como un namespace atravesable (`0755`) y restringe sólo su subcarpeta `backups`; esto permite que el usuario aislado de la terminal lea su llave privada sin exponer los respaldos.
- La sincronización migra ahora también las raíces de dominios principales desde `/var/www` o `/srv/www` al hogar real de la cuenta. Las operaciones destructivas o recursivas de un sitio excluyen su carpeta reservada `subdomains/`, evitando que despliegues, restauraciones o cambios de propietario del padre alteren sitios hijos.
- Los dominios nuevos viven en `public_html/<dominio>` y sus subdominios en `public_html/<dominio>/subdomains/<etiqueta>`; la sincronización migra las raíces planas heredadas y el gestor protege las carpetas estructurales contra borrado o renombrado accidental.

- Se retiró por completo el Builder visual abandonado —accesos global y por sitio, ruta y vistas— para no presentar módulos que ya no forman parte del producto.

- Los selectores activos de sitio y subdominio eliminan iconos redundantes para ganar espacio; el desplegable de sitios incorpora un switch persistente que muestra u oculta el selector independiente de subdominios de la cabecera.
- El ancho del sidebar se declara como una variable CSS inline real, por lo que sus consumidores de ancho y margen heredan `310px` sin depender de que Tailwind haya generado una utilidad arbitraria específica.
- El historial global de CPU, RAM, solicitudes HTTP, transferencia e I/O vive ahora en «Resumen del servidor»; «Uso de recursos» queda enfocado en la huella real del sitio, con indicadores circulares de disco e inodos y sus métricas propias.
- El inicio muestra KPIs, recursos, sitios y actividad real del servidor; correo y XMail tienen una jerarquía visual más clara, ajustes comparte el contenedor principal y la cabecera permite navegar sitios y subdominios por separado.
- Los indicadores pequeños de las páginas internas de un sitio se agrupan en franjas compactas junto a su encabezado, aprovechando mejor el espacio disponible con el menú secundario abierto.
- PageSpeed presenta los resultados Lighthouse con puntuaciones, métricas, oportunidades e historial mejor jerarquizados; Uso de recursos muestra disco, memoria, carga de CPU y entorno mediante lecturas reales del servidor, sin gráficas históricas simuladas.

### Corregido

- La barra de pestañas de iKode ya no se oculta al abrir imágenes, documentos o formatos sin vista previa; sólo se oculta el cuerpo de Monaco y el panel de previsualización comienza debajo de la pestaña activa.
- El API del gestor detecta texto por contenido además de la extensión, por lo que `.env`, `.env.local` y otros nombres compuestos llegan explícitamente como editables.
- Las actualizaciones de instalaciones anteriores crean el hogar Unix y las carpetas de la cuenta, reparan llaves privadas inválidas del agente, regeneran su llave pública y resincronizan el acceso de la cuenta y de cada sitio.
- Restaurar el diseño de iKode ya no abre automáticamente la terminal SSH varias veces mientras carga Monaco, evitando consumir capacidades de un solo uso y alcanzar el limitador.
- iKode reconoce `.env.local` y variantes `.env.*` como texto editable, dibuja la pestaña antes de leer el archivo y respeta el fondo del tema claro en las previsualizaciones.
- La terminal real no intenta crear tokens durante el desarrollo visual sin servicios Linux, limita los reintentos desde la interfaz y el agente acepta las identidades Unix de cuenta `xpa*` y de instancia `xhi*` además de los usuarios por sitio.
- El gestor general deriva de forma segura su usuario y hogar cuando una caché de configuración anterior todavía no contiene las nuevas claves de cuenta.

- `xpanel update` y todos los procesos del servidor fijan el directorio accesible del proyecto antes de ejecutar helpers; iniciar la actualización desde `/root` ya no provoca `proc_open(): posix_spawn() failed: Permission denied` al cambiar a `www-data`.

- PageSpeed ya no reintenta respuestas 429 ni muestra el error JSON de Google: diferencia entre cuota pública agotada, cuota propia agotada y una clave rechazada; la vista informa además qué tipo de cuota está utilizando.
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
