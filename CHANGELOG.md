# Registro de cambios

Los cambios importantes de XPanel Host se documentarán aquí. El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y las versiones estables seguirán [Versionado Semántico](https://semver.org/lang/es/).

## [Sin publicar]

### Añadido

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
- XMail funcional junto a Roundcube: autenticación propia por buzón, carpetas, lectura, envío SMTP autenticado, adjuntos y acciones IMAP.

### Corregido

- Las operaciones de sitios y SSL ya no interrumpen su propia respuesta HTTP al recargar PHP-FPM; la recarga se ejecuta de forma diferida.
- La sincronización de sitios repara dominios principales ausentes en el portafolio de Dominios.
- El editor iKode puede crear, guardar, mover y subir archivos mediante ACL confinadas al document root de cada sitio.
- WordPress verifica el núcleo oficial antes de configurarlo, funciona sin terminal interactivo e instala después el idioma seleccionado.
- Las vistas de Ajustes comparten navegación y estructura visual.
- iKode crea archivos vacíos mediante su operación dedicada y respeta la carpeta actual tanto desde la barra como desde el menú contextual.
- Los hosts HTTPS sin certificado propio se rechazan y nunca heredan el contenido ni el certificado del primer sitio de Nginx.
- Los subdominios usan raíces independientes por FQDN; la sincronización migra el formato anterior anidado sin sobrescribir destinos.
- El administrador de un dominio con subdominios muestra el sitio y cada subdominio como carpetas hermanas por FQDN en la raíz; los archivos reales solo aparecen al entrar en cada una, sin duplicarlos ni anidarlos físicamente.
- Un fallo auxiliar de instalación de la CLI ya no impide mostrar la URL y el estado final del panel.
- El helper privilegiado se versiona como ejecutable para que el instalador no ensucie el árbol Git ni bloquee `git pull`/`xpanel update`.
- Primera instalación completamente no interactiva por `IP:80`, sin solicitar dominio/correo ni intentar certificados del panel o webmail.
- Terminal real por sitio en iKode (`Avanzado → Acceso SSH → Terminal real desde el navegador`), opcional y apagada por defecto (`XPANEL_TERMINAL_ENABLED`). Un agente Go sin privilegios de root (`xpanel-terminal-agent`) hace de puente entre un WebSocket firmado de un solo uso y una sesión SSH real hacia el mismo sshd que ya aísla cada sitio por usuario Unix; el agente nunca toca la base de datos de Laravel ni `APP_KEY`.
- La terminal real (SSH por llave propia o terminal web) queda enjaulada con `ChrootDirectory` de sshd: cada sesión solo alcanza el document root del sitio y el de sus propios subdominios; ningún otro sitio del servidor es visible ni accesible desde ahí. Los binarios del sistema montados dentro de la jaula quedan de solo lectura.
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
