#!/bin/bash
#
# Deploy para producao (droplet Digital Ocean).
# Uso: ./deploy.sh
#
# Migrations e caches rodam no entrypoint do container. O --wait so termina quando
# o /up responde: se uma migration falhar, o deploy para aqui com erro.

set -euo pipefail

COMPOSE_FILE="docker-compose.prod.yml"

# Primeira execucao: cria o .env com as chaves geradas e para para informar o endereco.
if [ ! -f .env ]; then
    APP_KEY="base64:$(openssl rand -base64 32)"
    DB_PASSWORD="$(openssl rand -hex 32)"
    sed -e "s|^APP_KEY=.*|APP_KEY=$APP_KEY|" -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" .env.production.example > .env
    chmod 600 .env
    echo "==> Arquivo .env criado com APP_KEY e DB_PASSWORD gerados."
    echo "==> Ajuste APP_URL no .env (nano .env) e rode ./deploy.sh de novo."
    exit 0
fi

if grep -q "SEU_IP_AQUI" .env; then
    echo "ERRO: ajuste APP_URL no .env com o endereco do servidor (ex.: https://1-2-3-4.sslip.io)." >&2
    exit 1
fi

echo "==> Puxando ultima versao do repositorio..."
git pull origin main

echo "==> Rebuildando imagem da aplicacao..."
docker compose -f "$COMPOSE_FILE" build app

echo "==> Subindo containers e aguardando a aplicacao responder..."
docker compose -f "$COMPOSE_FILE" up -d --force-recreate --wait --wait-timeout 180 app

echo "==> Subindo o backup diario e o HTTPS (Caddy)..."
docker compose -f "$COMPOSE_FILE" up -d backup caddy

echo "==> Limpando imagens antigas..."
docker image prune -f
# Roda depois do build: o que este deploy usou fica em cache; o que esta parado ha 3 dias sai.
docker builder prune -f --filter until=72h

echo "==> Status dos containers:"
docker compose -f "$COMPOSE_FILE" ps

echo ""
echo "==> Deploy finalizado. Logs: docker compose -f $COMPOSE_FILE logs -f app"
