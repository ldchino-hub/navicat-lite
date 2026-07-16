# Deploy pipeline — db.ldjr.me

Sube **DB Tool Box PHP** al FTP de GoDaddy/hosting vía `curl`.

## Setup (una vez)

```bash
cd navicat-php
cp deploy/ftp.env.example deploy/ftp.env
# Edita deploy/ftp.env con tu contraseña FTP
npm run setup:frontend   # si aún no tienes node_modules
```

## Comandos

| Comando | Qué sube | Cuándo usarlo |
|---------|----------|---------------|
| `npm run deploy` | Build + frontend + backend PHP + routers | Release completa |
| `npm run deploy:frontend` | Solo `public/index.html` + `public/assets/` | Cambios en UI (`navicat-ui`) |
| `npm run deploy:backend` | Solo `src/`, `migrations/`, `scripts/*.php`, `public/index.php`, `.htaccess` | Cambios solo en PHP |
| `npm run deploy:verify` | No sube nada; prueba `https://db.ldjr.me` | Tras un deploy |

También puedes pasar la contraseña por env sin archivo:

```bash
FTP_PASS='...' npm run deploy:frontend
```

## Qué NO se sube por defecto

- `config/config.php` — evita pisar secretos del servidor. Forzar: `DEPLOY_UPLOAD_CONFIG=1 npm run deploy`
- `public/check.php` — diagnóstico. Forzar: `DEPLOY_UPLOAD_CHECK=1 npm run deploy`
- `public/recover-admin.php` — recuperación de admin (solo emergencia, ver abajo)

## Recuperar login admin (FTP, sin SSH)

Si el login devuelve `Invalid credentials` pero `/api/health` responde OK:

1. Por FTP, crea archivo vacío `storage/.recover-admin`
2. Sube `public/recover-admin.php` (está en el repo)
3. Abre `https://tu-dominio/recover-admin.php` una vez — usa `admin_email` / `admin_password` de `config/config.php` del servidor
4. Borra `public/recover-admin.php` y `storage/.recover-admin` inmediatamente
- `storage/` — SQLite y backups viven en el servidor
- `frontend/`, `node_modules/`, `.git`

## Limpieza de assets

Tras cada deploy de frontend se borran en FTP los `.js`/`.css`/`.map` viejos que ya no están en tu build local (evita acumular bundles obsoletos).

## CI (GitHub Actions)

Workflow manual: **Actions → Deploy db.ldjr.me → Run workflow**.

## Jenkins local

Ver **`deploy/jenkins/README.md`**. Job: `db-toolbox-ldjr` en http://127.0.0.1:8090/

Secrets del repo:

| Secret | Valor |
|--------|-------|
| `FTP_HOST` | `ldjr.me` |
| `FTP_USER` | `db@ldjr.me` |
| `FTP_PASS` | contraseña FTP |
| `DEPLOY_URL` | `https://db.ldjr.me` |

## Estructura en el servidor

```
/                          ← home FTP (db@ldjr.me)
  public/                  ← document root → https://db.ldjr.me/
  src/
  config/
  migrations/
  scripts/
  storage/                 ← no se sobrescribe en deploy
  .htaccess + index.php    ← fallback si docroot fuera public/
```
