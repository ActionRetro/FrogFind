FROM composer:2.6.5 AS composer

# Copying the source directory and install the dependencies with composer
COPY . /app

# Run composer install to install the dependencies
RUN composer install \
    --optimize-autoloader \
    --no-interaction \
    --no-progress

# Continue stage build with the desired image and copy the source including the dependencies downloaded by composer
FROM trafex/php-nginx:3.4.0
COPY --chown=nginx --from=composer /app /var/www/html
