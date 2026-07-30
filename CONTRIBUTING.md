# Contribuir a XPanel Host

Gracias por ayudar a construir XPanel Host. El proyecto todavía está en desarrollo activo: abre primero un issue para cambios grandes de arquitectura o comportamiento.

Al participar aceptas el [Código de conducta](CODE_OF_CONDUCT.md). Las vulnerabilidades se reportan por el canal privado indicado en [SECURITY.md](SECURITY.md), nunca mediante un issue público.

## Preparar el entorno

Necesitas PHP 8.3 o posterior, Composer, Node.js 22 o posterior, npm y las extensiones PHP usadas por Laravel y SQLite.

```bash
git clone https://github.com/xpanel-sh/xpanel-host.git
cd xpanel-host
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
```

## Antes de enviar cambios

Ejecuta las verificaciones relevantes:

```bash
php artisan test
vendor/bin/pint --test
npm run build
bash -n install.sh scripts/*.sh bin/*
```

- Mantén cada pull request enfocado en un problema.
- Añade pruebas para cambios de comportamiento y actualiza la documentación correspondiente.
- No incluyas secretos, credenciales, datos reales ni archivos `.env`.
- Conserva la compatibilidad de migraciones y la idempotencia del instalador y actualizador.
- Evita operaciones destructivas sin validación del alcance y una ruta de recuperación.
- Explica cómo probaste el cambio y cualquier impacto operativo o de seguridad.

## Commits y pull requests

Usa mensajes breves en modo imperativo. Vincula el issue relacionado cuando exista y completa la lista de verificación del pull request. Un mantenedor puede pedir que el cambio se divida si mezcla objetivos independientes.

Salvo que indiques expresamente lo contrario, toda contribución enviada intencionalmente se ofrece bajo la Apache License 2.0, conforme a la sección 5 de dicha licencia.
