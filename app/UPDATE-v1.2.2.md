# PHP — Actualizar a v1.2.2

```bash
git fetch --tags origin
git checkout v1.2.2
cat VERSION   # → 1.2.2
```

## Hosting (FTP / Apache)

```bash
npm run setup:frontend    # primera vez
npm run build:frontend
npm run deploy            # o deploy:frontend / deploy:backend
npm run deploy:verify
```

Ver `deploy/README.md`. No se sobrescribe `config/config.php` por defecto.

## Docker / NAS

```bash
cd /ruta/navicat-php-1.0.0
git checkout v1.2.2
# reiniciar contenedor o stack según tu infra
curl -s http://HOST:8788/api/health
```

## MongoDB

- Driver incluido (`src/Mongo/`); extensiones PHP extra no necesarias.
- Guía: `MONGODB_INTEGRATION.md`.

## Guía completa (Web + NAS + checklist)

En el repo Web: **`navicat-web-1.0.0/UPDATE-v1.2.2.md`** (mismo tag `v1.2.2`).
