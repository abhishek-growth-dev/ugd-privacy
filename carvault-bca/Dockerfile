FROM php:8.3-apache
RUN docker-php-ext-install mysqli \
    && (a2dismod mpm_event mpm_worker || true) \
    && a2enmod mpm_prefork rewrite
WORKDIR /var/www/html
COPY index.php /var/www/html/index.php
RUN chown -R www-data:www-data /var/www/html
EXPOSE 80
CMD ["apache2-foreground"]
