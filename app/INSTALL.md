# DB Tool Box PHP — v1.2.2

> Actualizar otra instancia: ver **`UPDATE-v1.2.2.md`** (y guía completa en repo Web, mismo tag).

Instalación en un servidor Apache/Nginx + PHP 8.1+.

## Requisitos

- PHP **8.1+** con extensiones: `pdo`, `pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`, `openssl`, `json`, `mbstring`
- Apache `mod_rewrite` o Nginx con fallback a `index.php`
- **No requiere Node.js** en producción (el frontend ya viene compilado en `public/`)

## Pasos

```bash
# 1. Descomprimir (ejemplo)
unzip navicat-php-1.0.0.zip -d /var/www/
cd /var/www/navicat-php-1.0.0

# 2. Configuración
cp config/config.example.php config/config.php
# Editar config.php:
#   meta_enc_key  → openssl rand -hex 32
#   jwt_secret    → openssl rand -hex 16
#   admin_email   → tu email

# 3. Permisos
mkdir -p storage/backups
chmod -R 775 storage

# 4. Usuario admin inicial
php scripts/seed-admin.php

# 5. (Opcional) Importar conexiones desde el servidor
php scripts/import-mycnf.php
php scripts/import-pgservice.php
```

## Document root

Apunta el dominio a la carpeta **`public/`** (obligatorio).

```
/var/www/navicat-php-1.0.0/public   ← DocumentRoot
```

No expongas `config/`, `src/` ni `storage/` directamente.

## Verificación

Abre `https://tu-dominio/check.php` — debe marcar ✓ en PHP, extensiones y build.
**Elimina `check.php` después.**

## Nginx (ejemplo)

```nginx
root /var/www/navicat-php-1.0.0/public;
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

## Backups

Los backups se guardan en `storage/backups/<conexion>/`.
Backup/restore nativo vía PDO (sin `mysqldump` / `pg_dump`).
