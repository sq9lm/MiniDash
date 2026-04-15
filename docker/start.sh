#!/bin/sh

# Create data directories if not exist
mkdir -p /var/www/html/data/avatars /var/www/html/logs
chown -R www-data:www-data /var/www/html/data /var/www/html/logs

# Create .env from environment variables if not exists
if [ ! -f /var/www/html/.env ]; then
    cat > /var/www/html/.env << EOF
UNIFI_CONTROLLER_URL=${UNIFI_CONTROLLER_URL:-https://10.0.0.1}
UNIFI_API_KEY=${UNIFI_API_KEY:-your-api-key}
UNIFI_SITE=${UNIFI_SITE:-default}
ADMIN_USERNAME=${ADMIN_USERNAME:-admin}
ADMIN_PASSWORD=${ADMIN_PASSWORD:-admin}
ADMIN_FULL_NAME=${ADMIN_FULL_NAME:-Admin}
ADMIN_EMAIL=${ADMIN_EMAIL:-admin@example.com}
DEBUG=${DEBUG:-false}
EOF
    chown www-data:www-data /var/www/html/.env
fi

# If .env was manually created (not by start.sh defaults), mark as installed
if [ -f /var/www/html/.env ] && grep -q "UNIFI_API_KEY=" /var/www/html/.env; then
    ENV_KEY=$(grep "UNIFI_API_KEY=" /var/www/html/.env | cut -d= -f2)
    if [ "$ENV_KEY" != "your-api-key" ] && [ -n "$ENV_KEY" ]; then
        touch /var/www/html/data/.installed
        chown www-data:www-data /var/www/html/data/.installed
    fi
fi

# Run migrations
php /var/www/html/db.php 2>/dev/null || true

# Fix ownership after migrations (db.php runs as root, creates files owned by root)
chown -R www-data:www-data /var/www/html/data /var/www/html/logs

# Start background trigger runner (every 60 seconds)
(while true; do
    php /var/www/html/cron_triggers.php 2>/dev/null
    sleep 60
done) &

# Start PHP-FPM and Nginx
php-fpm -D
nginx -g "daemon off;"
