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

### Seguridad

- Confinamiento del gestor de archivos al espacio administrado.
- Validación de acciones privilegiadas y backends internos limitados a loopback.

[Sin publicar]: https://github.com/xpanel-sh/xpanel-host/commits/main
