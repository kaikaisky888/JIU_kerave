FROM php:7.4-apache

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        mysqli \
        pcntl \
        pdo_mysql \
        posix \
        sockets \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
    && ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load \
    && ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/entrypoint-web.sh /usr/local/bin/entrypoint-web

COPY . /var/www/html

RUN chmod +x /usr/local/bin/entrypoint-web \
    && mkdir -p /var/www/html/runtime /var/www/html/public/upload \
    && chown -R www-data:www-data /var/www/html/runtime /var/www/html/public/upload

EXPOSE 80 1236 2000 2348

ENTRYPOINT ["entrypoint-web"]
CMD ["apache2-foreground"]
