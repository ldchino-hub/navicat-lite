# DB Tool Box Lite

Shared-hosting-friendly PHP edition of DB Tool Box (v1.0.0).

- **Edition:** `lite` — no Scheduler, Collectors, DPA, Query Store, AI Analysis, DBA Ops, OS Shell, or in-app System Update
- **Runtime:** PHP 8.3 + Apache (Docker) or plain PHP on shared hosting
- **Prod Pi:** http://192.168.100.152:8789
- **Prod shared:** https://db.ldjr.me

## Quick start (Docker)

```bash
cp .env.example .env
# set META_ENC_KEY / JWT_SECRET / ADMIN_*
docker compose up -d --build
curl -s http://127.0.0.1:8789/api/health
```

## Shared hosting

Upload `app/` contents (with built `public/`) to the docroot. Copy `config/config.example.php` → `config/config.php` and set secrets. Ensure `storage/` is writable.

## License

Private — ldchino-hub
