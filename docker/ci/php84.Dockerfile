FROM php:8.4-cli-bookworm

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        jq \
        libcurl4-openssl-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        dom \
        intl \
        mbstring \
        pcntl \
        pdo_mysql \
        xml \
        zip \
    && pecl install redis pcov \
    && docker-php-ext-enable redis pcov \
    && php -r 'foreach (["bcmath", "curl", "dom", "fileinfo", "intl", "mbstring", "openssl", "pcntl", "pdo_mysql", "redis", "sodium", "xml", "pcov"] as $extension) { if (! extension_loaded($extension)) { fwrite(STDERR, "Missing extension: {$extension}\n"); exit(1); } }' \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2.10.2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /workspace
