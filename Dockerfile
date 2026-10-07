FROM php:8.3-cli-alpine

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# PHP's default limits (2 MB per file) are lower than UPLOAD_MAX_BYTES.
RUN printf 'upload_max_filesize=10M\npost_max_size=11M\nexpose_php=Off\n' \
    > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

COPY public/ public/
COPY src/ src/

USER www-data
EXPOSE 8080

# PHP's built-in server: enough for a proof of concept, not for production.
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
