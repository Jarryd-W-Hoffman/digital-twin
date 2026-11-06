# Meridian in Docker

A local stack for Meridian, based on the [containers](https://github.com/Jarryd-W-Hoffman/containers/tree/main/laravel) Laravel template. The app code stays on the host and is bind-mounted in.

| Service | Image | Host port | |
|---|---|---|---|
| `php` | `php:8.5-fpm-alpine` (custom) | | PHP-FPM with pgsql, redis, zip, Xdebug (off), Composer, Node and npm 11 |
| `nginx` | `nginx:1.29-alpine` | `8085` | The app; accepts uploads up to 600 MB |
| `supervisor` | `php:8.5-cli-alpine` (custom) | | Queue worker and scheduler |
| `postgres` | `postgres:18-alpine` | `5436` | Database |
| `redis` | `redis:7-alpine` | `6385` | Cache, queue, sessions |
| `mailpit` | `axllent/mailpit` | `1030`, `8030` | Mail capture |
| `rustfs` | `rustfs/rustfs` | `9014`, `9015` | S3-compatible storage for uploads; `rustfs-buckets` creates the bucket |
| `gateway` | `node:24-alpine` | `8788` | Streams one twin, loaded from the control plane (profile `gateway`) |
| `vite` | the php image | `5199` | Hot reload (profile `dev`) |

## Start

```bash
cd docker
cp .env.example .env
# Merge app.env.example into the project's own .env (DB, Redis, RustFS hosts).

docker compose up -d --build
docker compose exec php composer install
docker compose exec php npm install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate --seed
docker compose exec php npm run build      # or: docker compose --profile dev up -d vite
```

Open http://localhost:8085 and log in as `test@example.com` / `password`. Mail is at http://localhost:8030 and the RustFS console at http://localhost:9015.

`node_modules` lives in a named volume, because the containers are Alpine (musl) and a host install is glibc. Run npm through the `php` container.

## The gateway

1. Open a twin, then **Layout → Gateway → Issue token**. The token is shown once.
2. Put it in `docker/.env` as `MERIDIAN_GATEWAY_TOKEN`.
3. `docker compose --profile gateway up -d gateway`

The gateway fetches the twin's latest version from `http://nginx`, checks for new versions every few seconds, and tells the control plane its public address (`ws://localhost:8788/ws`). The twin page then offers **Live** next to the simulator. Readings go to `POST http://localhost:8788/ingest` with the same token as a bearer token.

## Useful commands

```bash
docker compose ps                         # status and health
docker compose logs -f php gateway
docker compose exec php php artisan tinker
docker compose exec supervisor supervisorctl status
docker compose down                       # add -v to delete the data volumes
```
