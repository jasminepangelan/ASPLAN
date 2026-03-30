FROM php:8.1-apache

# Match Railway's expected HTTP port.
RUN sed -ri 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -ri 's/:80>/:8080>/' /etc/apache2/sites-available/000-default.conf

# Enable Apache modules required by the legacy app's .htaccess rules.
RUN a2enmod rewrite headers

# Install PHP extensions used by the app.
RUN docker-php-ext-install mysqli pdo pdo_mysql

WORKDIR /var/www/html

COPY . /var/www/html

# Ensure Apache can serve uploaded/generated files if needed.
RUN chown -R www-data:www-data /var/www/html

EXPOSE 8080
