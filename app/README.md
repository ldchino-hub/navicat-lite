# DB Tool Box PHP

**Versión actual: 1.2.2** — actualizar instancias: [`UPDATE-v1.2.2.md`](UPDATE-v1.2.2.md) · tag Git `v1.2.2`

PHP hosting edition of **DB Tool Box** (Apache/Nginx + PHP 8.1+). Independent from the Node edition — does not modify the Web product.

## Stack

| Capa | Tecnología |
|------|------------|
| Frontend | React 18 + Vite (shared UI with DB Tool Box Web) |
| Backend | PHP 8.1+ puro (PDO, JWT, AES-256-GCM) |
| Metadata | SQLite (`storage/database.sqlite`) |
| DB targets | MySQL + PostgreSQL (PDO) + MongoDB / DocumentDB (PHP puro, ver `MONGODB_INTEGRATION.md`) |

## Requisitos del host PHP

- PHP **8.1+** con extensiones: `pdo`, `pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`, `openssl`, `json`, `mbstring`
- Apache `mod_rewrite` **o** Nginx con fallback a `index.php`
- Backup/restore nativo vía PDO (sin `mysqldump` / `pg_dump`)
- Node.js **solo en build** (no necesario en producción si subís `public/` ya compilado)

## Instalación rápida

```bash
cd ~/navicat-php

# 1. Config
cp config/config.example.php config/config.php
# Edita config.php: meta_enc_key (64 hex), jwt_secret, admin_email

# 2. Generar claves
openssl rand -hex 32   # meta_enc_key
openssl rand -hex 16   # jwt_secret

# 3. Permisos
chmod -R 775 storage
chmod -R 775 storage/backups

# 4. Admin inicial
php scripts/seed-admin.php

# 5. (Opcional) Importar conexiones
php scripts/import-mycnf.php
php scripts/import-pgservice.php

# 6. Build frontend (en máquina con Node)
cd frontend && pnpm install && pnpm build
# El build escribe en ../public/
```

## Deploy a db.ldjr.me (FTP)

Pipeline documentado en **`deploy/README.md`**.

```bash
cp deploy/ftp.env.example deploy/ftp.env   # contraseña FTP (gitignored)
npm run deploy              # release completa (build + PHP + frontend)
npm run deploy:frontend     # solo UI (cambios en navicat-ui)
npm run deploy:backend      # solo PHP
npm run deploy:verify         # comprobar https://db.ldjr.me
```

`config/config.php` **no se sube** por defecto (protege secretos del servidor).

## Deploy Apache

Document root = **`public/`** (obligatorio). No apuntes el dominio a la raíz del repo.

Sube **todo el proyecto** (`config/`, `src/`, `storage/`, `migrations/`, `public/`), no solo `public/`.

`.htaccess` incluido:
- `/api/*` → `index.php` (Router PHP)
- resto → SPA React (`index.html` + assets)

### Diagnóstico rápido

Abre en el navegador: **`https://tu-dominio.com/check.php`**

Marca ✓/✗ en PHP, extensiones, permisos, build del frontend y SQLite. **Borrá `check.php` después.**

### Problemas frecuentes

| Síntoma | Causa usual |
|---------|-------------|
| Página en blanco | DocumentRoot no es `public/`, o falta `index.html` / `assets/` |
| Error 500 | `storage/` sin permisos de escritura, PHP &lt; 8.1, falta `config.php` |
| Login falla / 401 | Hosting no pasa header `Authorization` — `.htaccess` ya lo intenta |
| Solo subiste `public/` | Falta `src/` y `config/` un nivel arriba de `public/` |
| App en subcarpeta | Descomenta `RewriteBase` en `public/.htaccess` |

Tras subir archivos (SSH o panel):

```bash
chmod -R 775 storage storage/backups
php scripts/seed-admin.php
```

## Deploy Nginx (ejemplo)

```nginx
root /var/www/navicat-php/public;
index index.php index.html;

location /api/ {
    try_files $uri /index.php?$query_string;
}

location / {
    try_files $uri $uri/ /index.html;
}

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

## Differences vs DB Tool Box Web (Node)

| Feature | Node (`navicat-web`) | PHP (`navicat-php`) |
|---------|----------------------|---------------------|
| Metadata DB | Postgres (Docker) | SQLite |
| Servidor | Fastify + PM2 | Apache/Nginx + PHP-FPM |
| VPN OpenVPN | Sí | No (UI muestra desconectado) |
| Backup | PDO nativo (SQL) | PDO nativo (SQL) |
| Autoload | npm/pnpm | Composer opcional (fallback PSR-4 manual) |

## Estructura

```
navicat-php/
  public/           ← document root (index.php + SPA build)
  config/           ← config.php (no commitear secretos)
  src/              ← backend PHP
  frontend/         ← shell Vite (UI en ../navicat-ui)
  storage/          ← SQLite + backups + schema cache
  scripts/          ← seed-admin, importadores
  migrations/       ← schema SQLite
```

## Login

Tras `php scripts/seed-admin.php`, usa el email/password mostrados en consola (por defecto `admin@local` si no configuraste `admin_password`).

## Desarrollo local (con PHP)

```bash
cd ~/navicat-php
npm run dev:backend    # PHP en :8081
npm run dev:frontend   # Vite shell en :5184 → UI en ../navicat-ui
```

> **UI compartida:** el frontend React está en `../navicat-ui/` (mismo código que navicat-web). Solo el shell Vite y `.env` son específicos de PHP.

## Notas de seguridad

- Cambia `meta_enc_key` y `jwt_secret` en producción.
- Protege `storage/` y `config/` (no deben ser públicos).
- Los backups usan solo PHP/PDO; no requieren binarios del sistema.
