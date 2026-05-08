FROM php:8.2-apache-bookworm

ENV ACCEPT_EULA=Y \
    APACHE_DOCUMENT_ROOT=/var/www/html/apps/crm-web

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg \
        libsqlite3-dev \
        unixodbc-dev \
        libzip-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        unzip \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && echo "deb [arch=amd64 signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends msodbcsql18 \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_sqlite zip gd \
    && pecl install sqlsrv-5.12.0 pdo_sqlsrv-5.12.0 \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && { \
        echo 'upload_max_filesize=20M'; \
        echo 'post_max_size=24M'; \
        echo 'memory_limit=256M'; \
    } > /usr/local/etc/php/conf.d/bilnex-crm-uploads.ini \
    && a2enmod rewrite headers \
    && sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

WORKDIR /var/www/html

COPY . /var/www/html
COPY apps/crm-web/docker/entrypoint.sh /usr/local/bin/bilnex-crm-entrypoint

RUN chmod +x /usr/local/bin/bilnex-crm-entrypoint \
    && mkdir -p /var/www/html/apps/crm-web/data \
    && chown -R www-data:www-data /var/www/html/apps/crm-web/data

EXPOSE 80

ENTRYPOINT ["bilnex-crm-entrypoint"]
CMD ["apache2-foreground"]
