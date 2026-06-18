#!/bin/bash
set -euo pipefail

APP_ROOT=/var/www/html
CONFIG="${APP_ROOT}/config/config.php"

if [ ! -f "$CONFIG" ]; then
  META_ENC_KEY="${META_ENC_KEY:?META_ENC_KEY required}"
  JWT_SECRET="${JWT_SECRET:?JWT_SECRET required}"
  cat > "$CONFIG" <<PHP
<?php
return [
    'app_name' => 'DB Tool Box PHP',
    'debug' => false,
    'database_path' => '${APP_ROOT}/storage/database.sqlite',
    'meta_enc_key' => '${META_ENC_KEY}',
    'jwt_secret' => '${JWT_SECRET}',
    'backup_dir' => '${APP_ROOT}/storage/backups',
    'my_cnf_path' => getenv('HOME') . '/.my.cnf',
    'pg_service_path' => getenv('HOME') . '/.pg_service.conf',
    'admin_email' => '${ADMIN_EMAIL:-admin@local}',
    'admin_password' => '${ADMIN_PASSWORD:-}',
    'vpn_enabled' => false,
    'vpn_config' => '/etc/openvpn/client/pci.ovpn',
    'vpn_auth' => '/etc/openvpn/client/pci.auth',
    'vpn_pidfile' => '/var/run/openvpn-navicat.pid',
    'vpn_log' => '/var/log/openvpn-navicat.log',
];
PHP
  chown www-data:www-data "$CONFIG"
  chmod 640 "$CONFIG"
fi

mkdir -p "${APP_ROOT}/storage/backups"
chown -R www-data:www-data "${APP_ROOT}/storage"

if [ "${SEED_ADMIN:-true}" = "true" ]; then
  php "${APP_ROOT}/scripts/seed-admin.php" || true
  chown -R www-data:www-data "${APP_ROOT}/storage"
fi

exec "$@"
