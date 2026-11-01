# PHP-FPM para conferir o PBL4 atrás de nginx, como numa hospedagem/VPS comum.
FROM php:8.3-fpm
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev && docker-php-ext-install pdo_sqlite && rm -rf /var/lib/apt/lists/*
