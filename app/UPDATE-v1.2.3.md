# Actualizar a v1.2.3 — DB Tool Box (PHP)

Release con **observabilidad** (DB Jobs, Server Logs, Query Store) y fix de PDO LIMIT binding.

## Tag

```bash
cd /DATA/navicat-php-1.0.0
git fetch origin && git checkout v1.2.3
```

Health: `curl -s http://HOST:8788/api/health` → `"version":"1.2.3"`.

---

## Cambios

- **Observabilidad**: rutas de db-jobs, query-store, logs en `Router.php`.
- **DbJobId**: helper para codificar/decodificar IDs de MySQL EVENTs.
- **PostgresDriver**: soporte db-jobs vía pg_cron.
- **Fix LIMIT**: `bindValue(PDO::PARAM_INT)` en `getTopQueries`, `getSlowQueryLog`, `getGeneralLog`.
- **`.gitignore`**: exclusión de `._*` macOS.

---

## Deploy NAS (Docker)

```bash
cd /DATA/navicat-php-1.0.0
git checkout v1.2.3
cd infra && docker compose build navicat-php && docker compose up -d navicat-php
```

## Deploy FTP

```bash
git checkout v1.2.3
npm run setup:frontend
npm run build:frontend
npm run deploy
npm run deploy:verify
```

Versión anterior: `v1.2.2`.
