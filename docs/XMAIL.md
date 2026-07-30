# XMail: autenticación y migración desde Roundcube

XMail será un cliente de correo por buzón, no una extensión de la sesión administrativa de XPanel Host.

## Identidad

El usuario inicia sesión con la dirección completa y la contraseña del buzón:

```text
usuario@dominio.com
contraseña del correo
```

La autenticación se valida contra Dovecot. Una sesión de XMail queda vinculada a una sola cuenta y todas las operaciones IMAP/SMTP se ejecutan únicamente como esa cuenta. Cambiar de buzón exige cerrar sesión y autenticarse de nuevo.

El propietario y los colaboradores de Host pueden crear cuentas, cambiar contraseñas y eliminarlas, pero su sesión del panel no concede acceso al contenido de los mensajes. Host almacena hashes no reversibles y no implementará un botón de suplantación administrativa.

## Sesiones

- Cookie propia para XMail, separada de la sesión de Host.
- Regeneración del identificador después del login.
- Rate limiting por IP y dirección de correo.
- Credencial del buzón cifrada sólo durante la sesión activa para conectar IMAP/SMTP.
- Cierre de sesión elimina la credencial y revoca la sesión.
- Protección CSRF y comprobación del buzón autenticado en todas las API.

## Contrato del backend pendiente

```text
POST   /xmail/login
POST   /xmail/logout
GET    /xmail/api/folders
POST   /xmail/api/folders
DELETE /xmail/api/folders
GET    /xmail/api/messages
GET    /xmail/api/message
POST   /xmail/api/flag
POST   /xmail/api/move
DELETE /xmail/api/message
POST   /xmail/api/send
GET    /xmail/api/attachment
```

El backend se conectará a Dovecot por IMAP y a Postfix submission por SMTP. El HTML de mensajes se mostrará dentro de un iframe aislado después de sanitizar contenido activo y bloquear recursos remotos por defecto.

## Migración

Roundcube y XMail utilizan el mismo Postfix, Dovecot y Maildir. No se copian ni migran mensajes:

```text
Fase 1: Roundcube estable; XMail deshabilitado
Fase 2: Roundcube estable; XMail beta por cuenta
Fase 3: XMail predeterminado; Roundcube como respaldo
```

`XPANEL_XMAIL_ENABLED` debe permanecer en `false` hasta completar el backend, las pruebas de aislamiento entre buzones y las pruebas físicas IMAP/SMTP en Linux.
