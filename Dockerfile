FROM php:8.3-cli

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_pgsql \
    && curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /var/cache/apt/archives/* /tmp/*

WORKDIR /app
