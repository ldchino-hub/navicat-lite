# Navicat Lite

Edición **ligera y autocontenida** de [DB Tool Box](https://github.com/ldchino-hub): cliente web para administrar MySQL, PostgreSQL y MongoDB desde un único contenedor Docker.

- **Backend:** PHP 8.3 + Apache  
- **Metadatos:** SQLite (sin PostgreSQL externo)  
- **UI:** React (bundle incluido en la imagen)  
- **Versión:** ver [`app/VERSION`](app/VERSION)

## Requisitos

- Docker + Docker Compose v2
- Red Docker `navicat-net` (o edita `docker-compose.yml`)

```bash
docker network create navicat-net 2>/dev/null || true
```

## Inicio rápido

```bash
git clone https://github.com/ldchino-hub/navicat-lite.git
cd navicat-lite
cp .env.example .env
# Edita .env: META_ENC_KEY, JWT_SECRET, ADMIN_EMAIL, ADMIN_PASSWORD

docker compose up -d --build
```

Abre **http://localhost:8789** (o el puerto definido en `NAVICAT_LITE_PORT`).

En el primer arranque se crea el usuario admin según `.env`. Cambia la contraseña en producción.

## Estructura

```
navicat-lite/
  Dockerfile           # Imagen Apache + PHP + UI embebida
  docker-compose.yml
  app/                 # Backend PHP (mismo núcleo que navicat-php)
  download/            # Página estática de descarga (opcional)
```

Los datos persistentes (SQLite, backups) viven en el volumen Docker `navicat-lite-storage`.

## Diferencias vs DB Tool Box completo

| | Navicat Lite | DB Tool Box Web + PHP |
|--|--------------|------------------------|
| Despliegue | 1 contenedor | Stack multi-servicio |
| Meta DB | SQLite | PostgreSQL (web) / SQLite (php) |
| VPN OpenVPN | No | Sí (web) |
| Ideal para | NAS, demo, hosting simple | Producción multi-usuario |

## Desarrollo

El código PHP está en `app/`. Para reconstruir la UI necesitas el repo [`navicat-ui`](https://github.com/ldchino-hub/navicat-ui) y seguir `app/deploy/README.md`.

## Licencia

MIT — ver [LICENSE](LICENSE).

## Proyectos relacionados

- [navicat-ui](https://github.com/ldchino-hub/navicat-ui) — interfaz compartida  
- [navicat-web-1.0.0](https://github.com/ldchino-hub/navicat-web-1.0.0) — edición Node  
- [navicat-php-1.0.0](https://github.com/ldchino-hub/navicat-php-1.0.0) — edición PHP standalone  
