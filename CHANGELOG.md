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

### Corregido

- Navegación del menú de Sitios hacia las pantallas funcionales de archivos, backups, bases, actividad, PHP y Cron.
- Estados dinámicos de SSL y backups en el resumen; las acciones aún no implementadas ya no se presentan como enlaces activos.

### Seguridad

- Confinamiento del gestor de archivos al espacio administrado.
- Validación de acciones privilegiadas y backends internos limitados a loopback.

[Sin publicar]: https://github.com/xpanel-sh/xpanel-host/commits/main
