FROM php:8.2-apache

# Extensiones necesarias para Laravel + firma XAdES (openssl) + PDF (gd)
RUN apt-get update && apt-get install -y libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev unzip git \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg

RUN docker-php-ext-install mysqli pdo pdo_mysql intl zip gd opcache

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

RUN a2enmod rewrite

# Permitir que el .htaccess de la raiz redirija todo a /public (DocumentRoot
# sigue siendo /var/www/html porque ahi vive composer.json y el .env).
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
