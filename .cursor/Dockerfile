# Cloud Agent base image for APES Newsroom (PHP 8.4 + Node 22).
# Extensions align with CI (.github/workflows/ci.yml / deploy.yml).
FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        git \
        curl \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        libxml2-dev \
        libonig-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        mbstring \
        pdo_mysql \
        bcmath \
        intl \
        zip \
        gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

# Composer 2
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node.js 22 (matches Actions setup-node)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Optional Cloudron CLI (user-local; not required for every agent boot):
#   npm install --prefix "$HOME/.local" cloudron@^9
#   export PATH="$HOME/.local/node_modules/.bin:$PATH"
# Documented in AGENTS.md and docs/deployment.md.
#
# Official php:8.4-cli already ships curl/xml/dom; CI also lists those for setup-php.

WORKDIR /workspace
