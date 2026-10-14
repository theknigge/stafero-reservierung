FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --ignore-platform-reqs

FROM php:8.3-apache

# PHP-Image bringt curl und pdo_sqlite bereits mit
ENV APACHE_DOCUMENT_ROOT=/var/www/app/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'expose_php = Off\nsession.use_strict_mode = 1\n' > "$PHP_INI_DIR/conf.d/zz-app.ini" \
 && printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/zz-hardening.conf \
 && a2enconf zz-hardening \
 && mkdir -p /data && chown www-data:www-data /data

COPY --from=vendor /app/vendor/ /var/www/app/vendor/
COPY src/ /var/www/app/src/
COPY public/ /var/www/app/public/
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

VOLUME /data
EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
