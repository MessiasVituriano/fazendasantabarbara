#!/bin/bash
set -e

cd /var/www

# Trocar a chave invalida o que foi criptografado: ela nao e gerada automaticamente.
if [[ "${APP_KEY:-}" != base64:* ]]; then
    echo "ERRO: defina APP_KEY no .env. Gere com: echo \"base64:\$(openssl rand -base64 32)\"" >&2
    exit 1
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate --force

# Fotos e anexos antigos vão para a pasta da fazenda (copia, confere, atualiza o banco e só então apaga o original).
# Sem nada a mover, termina na hora.
php artisan pecuaria:organizar-arquivos

# Os comandos acima rodam como root; o PHP-FPM e o agendador rodam como www-data.
chown -R www-data:www-data storage bootstrap/cache

echo "==> Iniciando Nginx, PHP-FPM e agendador"

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
