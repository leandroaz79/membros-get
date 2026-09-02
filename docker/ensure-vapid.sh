#!/bin/sh
set -e

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

mkdir -p .docker

ENV_FILE=".docker/stack.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "Arquivo $ENV_FILE não encontrado. Rode docker/up.sh primeiro." >&2
  exit 1
fi

COMPOSE_FILES="${GETFY_COMPOSE_FILES:-docker-compose.yml}"
COMPOSE_ARGS=""
OLD_IFS="$IFS"
IFS=';'
for f in $COMPOSE_FILES; do
  if [ -n "$f" ]; then
    COMPOSE_ARGS="$COMPOSE_ARGS -f $f"
  fi
done
IFS="$OLD_IFS"

echo "Verificando chaves VAPID (PWA)..." >&2

for i in $(seq 1 60); do
  if docker compose $COMPOSE_ARGS --env-file "$ENV_FILE" exec -T app curl -fsS http://127.0.0.1/up >/dev/null 2>&1; then
    break
  fi
  if [ "$i" -eq 60 ]; then
    echo "Aviso: container app não respondeu a tempo; tentando pwa:vapid mesmo assim." >&2
  fi
  sleep 2
done

if ! docker compose $COMPOSE_ARGS --env-file "$ENV_FILE" exec -T app php artisan pwa:vapid; then
  echo "Aviso: não foi possível garantir chaves VAPID. Veja os logs do container app." >&2
  exit 0
fi

docker compose $COMPOSE_ARGS --env-file "$ENV_FILE" restart queue 2>/dev/null || true

echo "Chaves VAPID verificadas." >&2
