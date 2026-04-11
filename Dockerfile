FROM php:8.2-fpm-alpine

# Install extensions needed by MiniDash
RUN apk add --no-cache \
    nginx \
    curl \
    sqlite \
    libsodium-dev \
    && docker-php-ext-install pdo pdo_sqlite sodium \
    && rm -rf /var/cache/apk/*

# Nginx config
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# PHP config
RUN echo "session.save_path = /var/lib/php/sessions" > /usr/local/etc/php/conf.d/minidash.ini \
    && echo "session.cookie_httponly = 1" >> /usr/local/etc/php/conf.d/minidash.ini \
    && echo "session.cookie_samesite = Strict" >> /usr/local/etc/php/conf.d/minidash.ini \
    && echo "display_errors = Off" >> /usr/local/etc/php/conf.d/minidash.ini \
    && echo "max_execution_time = 30" >> /usr/local/etc/php/conf.d/minidash.ini \
    && mkdir -p /var/lib/php/sessions \
    && chown www-data:www-data /var/lib/php/sessions

# Copy application
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html/data /var/www/html/logs \
    && chmod -R 770 /var/www/html/data /var/www/html/logs

# Start script
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80

CMD ["/start.sh"]
