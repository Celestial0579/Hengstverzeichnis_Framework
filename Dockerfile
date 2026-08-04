FROM php:8.5-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers expires

# Docroot direkt auf public/ setzen, damit src/, config/ und database/
# außerhalb des von Apache ausgelieferten Verzeichnisses liegen.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

COPY . .

# config/ (Setup-Wizard schreibt db_config.php) und public/uploads
# (Bild-Uploads) müssen für www-data beschreibbar sein.
RUN chown -R www-data:www-data config public/uploads \
    && chmod -R u+rwX config public/uploads
