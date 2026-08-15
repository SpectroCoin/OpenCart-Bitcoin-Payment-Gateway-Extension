# OpenCart publishes no official Docker image, so build one from the release
# the project actually ships. Kept minimal and pinned by build arg.
FROM php:8.2-apache

ARG OC_VERSION=4.1.0.4

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libicu-dev unzip curl default-mysql-client \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql gd zip intl opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL -o /tmp/oc.zip \
      "https://github.com/opencart/opencart/releases/download/${OC_VERSION}/opencart-${OC_VERSION}.zip" \
 && unzip -q /tmp/oc.zip -d /tmp/oc \
 && cp -a /tmp/oc/upload/. /var/www/html/ \
 && rm -rf /tmp/oc /tmp/oc.zip \
 && cp /var/www/html/config-dist.php /var/www/html/config.php \
 && cp /var/www/html/admin/config-dist.php /var/www/html/admin/config.php \
 && mkdir -p /var/www/html/system/storage/{cache,logs,download,upload,session,vendor} \
 && chown -R www-data:www-data /var/www/html

# Surface everything: a warning during extension load is a finding.
RUN { echo 'display_errors=Off'; echo 'log_errors=On'; \
      echo 'error_log=/var/www/html/system/storage/logs/php_error.log'; \
      echo 'error_reporting=E_ALL'; } > /usr/local/etc/php/conf.d/zz-smoke.ini
