FROM php:8.2-apache

# Match Railway's expected HTTP port.
RUN sed -ri 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -ri 's/:80>/:8080>/' /etc/apache2/sites-available/000-default.conf

# Ensure Apache runs with a single MPM. php:apache should use prefork.
RUN a2dismod mpm_event || true \
    && a2dismod mpm_worker || true \
    && rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf \
    && rm -f /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf \
    && ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load \
    && ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf \
    && a2enmod rewrite headers \
    && apache2ctl -M | grep mpm_prefork

# Install PHP extensions used by the app.
RUN docker-php-ext-install mysqli pdo pdo_mysql

WORKDIR /var/www/html

COPY . /var/www/html

# Ensure Apache can serve uploaded/generated files if needed.
RUN chown -R www-data:www-data /var/www/html

EXPOSE 8080
