#!/bin/bash
set -e

echo "Starting application initialization..."

# Clear and warm up cache for production
echo "Warming up cache..."
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod

echo "Starting supervisord (PHP-FPM + Caddy)..."

# Start supervisord which manages PHP-FPM and Caddy
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
