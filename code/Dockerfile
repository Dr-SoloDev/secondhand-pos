FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libonig-dev \
    curl zip unzip git default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql mysqli gd zip mbstring bcmath \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

RUN echo "upload_max_filesize=32M\npost_max_size=32M\nmemory_limit=256M\nmax_execution_time=300\ndate.timezone=Asia/Bangkok" \
    > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

COPY base-pos/ /var/www/html/
COPY customizations/ /var/www/customizations/
COPY docker/apache-config.conf /etc/apache2/sites-available/000-default.conf
COPY docker/railway-entrypoint.sh /railway-entrypoint.sh

RUN mkdir -p uploads backups temp \
    && chown -R www-data:www-data /var/www/html /var/www/customizations \
    && chmod +x /railway-entrypoint.sh

EXPOSE 80
CMD ["/railway-entrypoint.sh"]
