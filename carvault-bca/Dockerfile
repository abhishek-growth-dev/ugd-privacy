FROM php:8.3-apache
RUN docker-php-ext-install mysqli && a2enmod rewrite
WORKDIR /var/www/html
COPY index.php /var/www/html/index.php
RUN chown -R www-data:www-data /var/www/html
ENV PORT=8080
EXPOSE 8080
CMD ["sh","-c","sed -i \"s/Listen 80/Listen ${PORT}/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT}>/\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
