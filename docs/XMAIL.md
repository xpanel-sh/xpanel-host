# XMail y Roundcube

XMail es el cliente de correo integrado de XPanel Host. Funciona junto a Roundcube y no es una extensión de la sesión administrativa.

## Identidad

El usuario inicia sesión con la dirección completa y la contraseña del buzón:

```text
usuario@dominio.com
contraseña del correo
```

La autenticación se valida contra Dovecot. Una sesión queda vinculada a una sola cuenta y todas las operaciones IMAP/SMTP se ejecutan únicamente como esa cuenta. Cambiar de buzón exige cerrar sesión y autenticarse de nuevo.

El propietario y los colaboradores de Host pueden crear cuentas, cambiar contraseñas y eliminarlas, pero su sesión del panel no concede acceso al contenido. Host almacena hashes no reversibles y no ofrece suplantación administrativa.

## Funciones disponibles

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

El backend se conecta a Dovecot por IMAP local con TLS y a Postfix submission por SMTP autenticado con STARTTLS. Permite listar y administrar carpetas, paginar y leer mensajes, descargar adjuntos, marcar, mover, borrar y enviar o responder. El HTML se sanea en el servidor, se bloquean recursos remotos y se muestra en un `iframe` sin scripts.

## Convivencia con Roundcube

Roundcube y XMail utilizan el mismo Postfix, Dovecot y Maildir. No se copian ni migran mensajes:

```text
Roundcube ─┐
           ├── Postfix + Dovecot + Maildir (mismos buzones)
XMail ─────┘
```

`XPANEL_XMAIL_ENABLED=true` publica XMail en `/xmail`. `XPANEL_ROUNDCUBE_ENABLED=true` mantiene Roundcube en el hostname configurado; ambas opciones pueden convivir.

## Límites de seguridad

- La cookie `xpanel_xmail_session` usa ruta `/xmail` y `SameSite=Strict`; no comparte la autenticación de Host.
- La credencial IMAP/SMTP se cifra con `APP_KEY`, se elimina al cerrar sesión y nunca se escribe en logs ni en la base de cuentas.
- El inicio de sesión se limita por combinación de IP y buzón, además de un límite global por IP.
- El remitente SMTP siempre es el buzón autenticado; Postfix conserva además su restricción `sender_login_maps`.
- Cada buzón limita destinatarios salientes por hora y por día (100/500 de forma predeterminada). Postfix atribuye el consumo al usuario SASL autenticado, rechaza nuevos destinatarios al alcanzar el cupo y vuelve a permitirlos al comenzar el siguiente periodo.
- Las webs alojadas no pueden evadir esos límites mediante el binario local `sendmail` ni conectándose directamente a un servidor SMTP externo. La salida a los puertos SMTP habituales 25, 465, 587 y 2525 queda reservada a Postfix, mientras localhost permanece disponible para XMail y aplicaciones. Los sitios deben enviar con una cuenta SMTP autenticada local por el puerto 587; los topes se ajustan desde **Correos → Límites**.
- Los nombres de carpeta, UID, destinatarios, tamaños y cabeceras se validan antes de llegar a IMAP o SMTP.
- Los recursos remotos y el contenido activo de mensajes HTML quedan bloqueados.
- Los cuerpos mayores a 10 MiB no se cargan en la vista y los adjuntos se limitan por `XPANEL_XMAIL_ATTACHMENT_MAX_BYTES` (50 MiB de forma predeterminada).

## Verificación en el VDS

La suite automatizada cubre sesión, autorización y contrato API. En el servidor Linux ejecuta además:

```bash
sudo bash scripts/smoke-host-services.sh
```

El smoke test comprueba PHP IMAP, las rutas XMail, Dovecot, Postfix y, si defines `XPANEL_SMOKE_MAIL_ACCOUNT` y `XPANEL_SMOKE_MAIL_PASSWORD`, una autenticación y entrega SMTP/IMAP real.
