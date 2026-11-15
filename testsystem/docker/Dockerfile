# PHP + Apache für Shopware 5.6 / 5.7
# PHP_VERSION sollte der Version des Live-Shops entsprechen (ionCube-verschlüsselte Plugins laufen nur
# mit der PHP-Version, für die sie verschlüsselt wurden).
ARG PHP_VERSION=7.4
FROM php:${PHP_VERSION}-apache

ARG HOST_UID=1000
ARG HOST_GID=1000
ARG INSTALL_IONCUBE=1

# Ältere Debian-Versionen (z. B. bei PHP 7.4) liegen inzwischen im Archiv -> Fallback auf archive.debian.org
RUN set -eux; \
    apt-get update || true; \
    if ! apt-cache show libicu-dev >/dev/null 2>&1; then \
        for f in /etc/apt/sources.list /etc/apt/sources.list.d/*; do \
            [ -f "$f" ] && sed -i -e 's|deb.debian.org|archive.debian.org|g' -e 's|security.debian.org|archive.debian.org|g' -e '/-updates/d' "$f"; \
        done; \
        apt-get -o Acquire::Check-Valid-Until=false update; \
    fi; \
    apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        msmtp msmtp-mta unzip curl ca-certificates; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql gd intl zip opcache; \
    pecl install apcu; \
    docker-php-ext-enable apcu; \
    a2enmod rewrite headers expires; \
    rm -rf /var/lib/apt/lists/*

# ionCube Loader (viele Shopware-Store-Plugins sind damit verschlüsselt)
RUN set -eux; \
    if [ "$INSTALL_IONCUBE" = "1" ]; then \
        arch="$(uname -m)"; \
        case "$arch" in x86_64) ic=x86-64 ;; aarch64) ic=aarch64 ;; *) ic="" ;; esac; \
        if [ -n "$ic" ]; then \
            curl -fsSL "https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_${ic}.tar.gz" | tar -xz -C /tmp; \
            phpv="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"; \
            if [ -f "/tmp/ioncube/ioncube_loader_lin_${phpv}.so" ]; then \
                cp "/tmp/ioncube/ioncube_loader_lin_${phpv}.so" "$(php -r 'echo ini_get("extension_dir");')/ioncube.so"; \
                echo "zend_extension=ioncube.so" > /usr/local/etc/php/conf.d/00-ioncube.ini; \
            else echo "Kein ionCube Loader für PHP ${phpv} verfügbar"; fi; \
            rm -rf /tmp/ioncube; \
        fi; \
    fi

COPY php.ini /usr/local/etc/php/conf.d/zz-shopware.ini
COPY msmtprc /etc/msmtprc

# www-data bekommt die UID/GID des Host-Benutzers, damit die Shop-Dateien beschreibbar bleiben
RUN set -eux; \
    if [ "$HOST_UID" != "0" ]; then \
        groupmod -o -g "$HOST_GID" www-data; \
        usermod -o -u "$HOST_UID" -g "$HOST_GID" www-data; \
    fi

WORKDIR /var/www/html
