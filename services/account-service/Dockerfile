# Stage 1: Build dependencies
FROM composer:2.7 AS deps
WORKDIR /app
COPY composer.json ./
# For a fresh laravel project, composer.lock might not exist yet if not run, so we copy what we have
COPY composer.* ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist || true

# Stage 2: Final minimal image
FROM php:8.3-cli-alpine

# Install system dependencies & PHP extensions needed for Laravel
RUN apk add --no-cache \
    curl \
    zip \
    unzip \
    libpng-dev \
    libzip-dev \
    oniguruma-dev \
    mariadb-client \
    redis \
    && docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath gd

# Create non-root user for security (Security Best Practices)
RUN addgroup -g 1001 -S appgroup && \
    adduser -S appuser -u 1001 -G appgroup

WORKDIR /app

# Copy project files and assign ownership
COPY --chown=appuser:appgroup . .
COPY --from=deps /app/vendor ./vendor

# Ensure storage and bootstrap/cache are writable
RUN mkdir -p storage framework/cache/data framework/views framework/sessions bootstrap/cache && \
    chown -R appuser:appgroup storage bootstrap/cache

# Switch to non-root user
USER 1001

# The port is driven by APP_PORT env variable (default to 8000)
CMD ["sh", "-c", "php artisan serve --host=0.0.0.0 --port=${APP_PORT:-8000}"]
