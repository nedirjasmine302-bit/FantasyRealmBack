#!/bin/sh
set -e

# 1. Port fourni par Railway (8080 par défaut en local). On l'injecte dans la conf nginx.
export PORT="${PORT:-8080}"
echo "[entrypoint] nginx ecoutera sur le port $PORT"
envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf

# 2. Clés JWT : générées avec la passphrase (variable Railway) si elles n'existent pas encore.
if [ ! -f config/jwt/private.pem ]; then
  echo "[entrypoint] Generation des cles JWT..."
  php bin/console lexik:jwt:generate-keypair --no-interaction --skip-if-exists
fi
chown -R www-data:www-data config/jwt

# 3. Cache de production.
echo "[entrypoint] Preparation du cache prod..."
php bin/console cache:clear --no-interaction || true
chown -R www-data:www-data var

# 4. Migrations (ne bloque pas le démarrage en cas d'échec : visible dans les logs).
echo "[entrypoint] Migrations base de donnees..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || echo "[entrypoint] ATTENTION: migrations en echec, voir les logs"

# 5. Démarre nginx + php-fpm via supervisor.
echo "[entrypoint] Demarrage de nginx + php-fpm"
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
