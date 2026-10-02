#!/bin/bash
set -e

# Cache configuration if APP_KEY is provided
if [ -n "$APP_KEY" ]; then
    php artisan config:clear || true
fi

# Ensure storage directories exist with proper permissions
mkdir -p /var/www/html/storage/app/file-converter/uploads \
         /var/www/html/storage/app/file-converter/processed \
         /var/www/html/storage/framework/cache \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# If PORT env is passed (Render passes $PORT, e.g. 10000), update Apache ports
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen $PORT/g" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:$PORT>/g" /etc/apache2/sites-available/*.conf
fi

exec "$@"
