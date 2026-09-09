FROM php:8.3-cli
RUN docker-php-ext-install mysqli
WORKDIR /app
COPY index.php /app/index.php
ENV PORT=8080
EXPOSE 8080
CMD ["sh","-c","php -S 0.0.0.0:${PORT:-8080} -t /app"]
