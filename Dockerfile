FROM php:8.3-apache

RUN a2enmod rewrite

RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# Composer for PHP-ML
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Сначала зависимости — для кэширования слоёв
COPY composer.json composer.lock* /var/www/html/
RUN composer install --no-dev --optimize-autoloader --no-scripts 2>/dev/null || true

COPY . /var/www/html/

RUN mkdir -p /var/www/html/data/rate_limits && \
    chown -R www-data:www-data /var/www/html/data && \
    chmod -R 775 /var/www/html/data

ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -i 's|<Directory /var/www/html/>|<Directory /var/www/html/>\n    AllowOverride All\n    Require all granted|g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|/var/www/|/var/www/html/|g' /etc/apache2/apache2.conf

RUN chown -R www-data:www-data /var/www/html

USER www-data

EXPOSE 80
