# Registro de cambios

Los cambios importantes de XPanel Host se documentarán aquí. El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y las versiones estables seguirán [Versionado Semántico](https://semver.org/lang/es/).

## [Sin publicar]

### Añadido

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

### Corregido

- Navegación del menú de Sitios hacia las pantallas funcionales de archivos, backups, bases, actividad, PHP y Cron.
- Estados dinámicos de SSL y backups en el resumen; las acciones aún no implementadas ya no se presentan como enlaces activos.
- Conservación del estado SSL activo cuando una reemisión falla y soporte de certificados para sitios OpenLiteSpeed.
- Bloqueo de eliminación directa de dominios vinculados para evitar vhosts, alias o certificados inconsistentes.

### Seguridad

- Confinamiento del gestor de archivos al espacio administrado.
- Validación de acciones privilegiadas y backends internos limitados a loopback.
- SSH permanece cerrado mientras los sitios compartan usuario de servicio; no se generan llaves ni shells que atraviesen el aislamiento.
- MySQL remoto rechaza comodines, CIDR y hostnames; las contraseñas viajan por entrada estándar y no se persisten en Host.

[Sin publicar]: https://github.com/xpanel-sh/xpanel-host/commits/main
