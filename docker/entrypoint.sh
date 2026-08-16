#!/bin/sh
set -e

export PORT="${PORT:-8080}"
echo "[entrypoint] nginx ecoutera sur le port $PORT"
envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf

if [ ! -f config/jwt/private.pem ]; then
  echo "[entrypoint] Generation des cles JWT..."
  php bin/console lexik:jwt:generate-keypair --no-interaction --skip-if-exists
fi
chown -R www-data:www-data config/jwt

echo "[entrypoint] Preparation du cache prod..."
php bin/console cache:clear --no-interaction || true
chown -R www-data:www-data var

echo "[entrypoint] Migrations base de donnees..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || echo "[entrypoint] ATTENTION: migrations en echec, voir les logs"

echo "[entrypoint] Demarrage de nginx + php-fpm"
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
