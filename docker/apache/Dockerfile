# Apache de verdade para conferir as regras de reescrita e de proteção (.htaccess), como numa hospedagem comum.
FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev unzip git \
    && docker-php-ext-install pdo_sqlite \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
# Permite .htaccess em qualquer pasta, como na maioria das hospedagens compartilhadas.
RUN printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/pbl4.conf && a2enconf pbl4
