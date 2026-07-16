<?php
return [
    'app_name' => 'DB Tool Box Lite',
    'debug' => false,

    // Metadata DB: sqlite (default) or mysql (shared hosting / db.ldjr.me)
    // 'database' => [
    //     'driver' => 'mysql',
    //     'host' => 'localhost',
    //     'port' => 3306,
    //     'database' => 'DBAdmin',
    //     'username' => 'Galilea',
    //     'password' => '',
    // ],
    'database_path' => __DIR__ . '/../storage/database.sqlite',

    // 64 hex chars (32 bytes) — generate: openssl rand -hex 32
    'meta_enc_key' => 'CHANGE_ME_64_HEX_CHARS_FOR_AES256_GCM_ENCRYPTION_KEY_HERE',

    // min 16 chars
    'jwt_secret' => 'CHANGE_ME_JWT_SECRET_MIN_16_CHARS',

    'backup_dir' => __DIR__ . '/../storage/backups',

    // Optional import sources (CLI scripts only)
    'my_cnf_path' => getenv('HOME') . '/.my.cnf',
    'pg_service_path' => getenv('HOME') . '/.pg_service.conf',

    'admin_email' => 'admin@local',
    'admin_password' => '',

    // VPN on-demand for *-vpn connections (requires openvpn + sudo on host)
    'vpn_enabled' => false,
    'vpn_config' => '/etc/openvpn/client/pci.ovpn',
    'vpn_auth' => '/etc/openvpn/client/pci.auth',
    'vpn_pidfile' => '/var/run/openvpn-navicat.pid',
    'vpn_log' => '/var/log/openvpn-navicat.log',
];
