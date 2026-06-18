# Navicat-Lite — DB Tool Box PHP v1.2.3 (mirrored from db.ldjr.me)
FROM php:8.3-apache-bookworm

RUN apt-get update \
  && apt-get install -y --no-install-recommends libpq-dev \
  && docker-php-ext-install pdo_mysql pdo_pgsql \
  && a2enmod rewrite headers \
  && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
  && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY app/deploy/docker/apache.conf /etc/apache2/conf-available/navicat-php.conf
RUN a2enconf navicat-php

WORKDIR /var/www/html

COPY app/composer.json app/VERSION ./
COPY app/config/config.example.php config/config.example.php
COPY app/migrations/ migrations/
COPY app/scripts/ scripts/
COPY app/src/ src/
COPY app/public/index.php app/public/.htaccess public/
COPY app/public/index.html public/index.html
COPY app/public/assets public/assets

COPY app/deploy/docker/docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh \
  && mkdir -p storage/backups \
  && chown -R www-data:www-data storage

VOLUME ["/var/www/html/storage"]

EXPOSE 80

ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
