FROM php:8.3-cli
RUN docker-php-ext-install mysqli
WORKDIR /app
COPY index.php /app/index.php
COPY seed_demo.php /app/seed_demo.php
COPY ui_patch.php /app/ui_patch.php
RUN php /app/ui_patch.php && rm /app/ui_patch.php
ENV PORT=8080
EXPOSE 8080
CMD ["sh","-c","php /app/seed_demo.php && php -S 0.0.0.0:${PORT:-8080} -t /app"]
