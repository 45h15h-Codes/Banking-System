# Stage 1: Build dependencies
FROM composer:2.7 AS deps
WORKDIR /app
COPY composer.json ./
COPY composer.* ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist || true

# Stage 2: Final image (Debian-based for stability)
FROM php:8.3-cli-bookworm

# Install system dependencies & PHP extensions needed for Laravel
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    zip \
    unzip \
    libpng-dev \
    libzip-dev \
    libonig-dev \
    default-mysql-client \
    && docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Create non-root user for security (Security Best Practices)
RUN groupadd -g 1001 appgroup && \
    useradd -u 1001 -g appgroup -s /bin/bash -m appuser

WORKDIR /app

# Copy project files and assign ownership
COPY --chown=appuser:appgroup . .
COPY --from=deps /app/vendor ./vendor

# Remove host cache files to avoid "PailServiceProvider not found"
RUN rm -f bootstrap/cache/*.php


# Ensure storage and bootstrap/cache are writable
RUN mkdir -p storage/framework/cache/data storage/framework/views storage/framework/sessions storage/logs bootstrap/cache && \
    chown -R appuser:appgroup storage bootstrap/cache

# Switch to non-root user
USER 1001

# The port is driven by APP_PORT env variable (default to 8000)
CMD ["sh", "-c", "php artisan serve --host=0.0.0.0 --port=${APP_PORT:-8000}"]
