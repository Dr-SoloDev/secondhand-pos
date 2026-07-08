FROM php:8.2-apache

# ติดตั้ง PHP extensions ที่ต้องใช้
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    curl \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mysqli \
        gd \
        zip \
        mbstring \
        bcmath \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# เพิ่มขนาด upload สำหรับรูปสินค้า
RUN echo "upload_max_filesize = 32M\npost_max_size = 32M\nmemory_limit = 256M\nmax_execution_time = 300\ndate.timezone = Asia/Bangkok" > /usr/local/etc/php/conf.d/uploads.ini

# Working directory
WORKDIR /var/www/html

# สร้าง folder สำหรับเก็บรูปและ backup
RUN mkdir -p /var/www/html/uploads /var/www/html/backups /var/www/html/temp \
    && chown -R www-data:www-data /var/www/html
