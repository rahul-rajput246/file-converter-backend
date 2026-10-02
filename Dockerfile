FROM php:8.2-apache

# Install system dependencies, ffmpeg & graphic libraries for GD (JPG, PNG, WebP, AVIF, GIF, BMP)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    ffmpeg \
    poppler-utils \
    ghostscript \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libwebp-dev \
    libavif-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Configure PHP & Apache for up to 100MB uploads, 512M memory, and 300s execution
RUN { \
        echo 'upload_max_filesize = 105M'; \
        echo 'post_max_size = 110M'; \
        echo 'memory_limit = 512M'; \
        echo 'max_execution_time = 300'; \
        echo 'max_input_time = 300'; \
    } > /usr/local/etc/php/conf.d/uploads.ini \
    && echo 'LimitRequestBody 115343360' >> /etc/apache2/apache2.conf

# Set Apache document root to Laravel public folder
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Configure Apache server name
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Production defaults
ENV APP_ENV=production
ENV APP_DEBUG=false
ENV APP_KEY=base64:v1ZfI3hW9V7M2n8Q6P4j0L5k8S1a3D5f7G9h2J4k6L8=

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Install dependencies (production, optimized autoloader)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Create necessary directories and set permissions for storage and cache
RUN mkdir -p storage/app/file-converter/uploads \
             storage/app/file-converter/processed \
             storage/framework/cache \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
