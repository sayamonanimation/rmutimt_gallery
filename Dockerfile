# rmutimt_gallery – PHP 8.2 + Apache + PostgreSQL (PDO) + GD + cURL
FROM php:8.2-apache

# ---- ส่วนขยาย PHP ที่ระบบต้องใช้ ----
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libcurl4-openssl-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql gd curl fileinfo zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ---- ค่า php.ini สำหรับอัปโหลดไฟล์ผลงานขนาดใหญ่ ----
RUN { \
        echo 'upload_max_filesize = 128M'; \
        echo 'post_max_size = 128M'; \
        echo 'memory_limit = 512M'; \
        echo 'max_execution_time = 300'; \
        echo 'max_input_time = 300'; \
        echo 'allow_url_fopen = On'; \
    } > /usr/local/etc/php/conf.d/zz-rmutimt.ini

# ---- Apache: เปิด mod_rewrite + ปิดการเข้าถึง .env ----
RUN a2enmod rewrite headers
RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    '<FilesMatch "^\.env">' \
    '    Require all denied' \
    '</FilesMatch>' \
    > /etc/apache2/conf-available/rmutimt.conf \
    && a2enconf rmutimt

# Render/หลายแพลตฟอร์มส่ง $PORT มาให้ – ให้ Apache ฟังพอร์ตนั้น
RUN sed -ri 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -ri 's/:80>/:${PORT}>/' /etc/apache2/sites-available/000-default.conf
ENV PORT=8080

WORKDIR /var/www/html
COPY . /var/www/html/

RUN mkdir -p uploads/admins uploads/advisors uploads/authors storage/temp \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/storage

EXPOSE 8080
CMD ["apache2-foreground"]
