FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        python3 \
        python3-pip \
        libzip-dev \
        zip \
        unzip \
    && docker-php-ext-install pdo pdo_mysql zip \
    && pip3 install --no-cache-dir --break-system-packages cryptography sqlalchemy pymysql \
    && ln -sf /usr/bin/python3 /usr/local/bin/python3.11 \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
