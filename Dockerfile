FROM php:8.5-apache

# ftp: für App\Service\FtpsClient (#93, FTPS als Backup-Ziel) - das
# FTP-Protokoll selbst lässt sich anders als S3/WebDAV nicht über PHP-Streams
# nachbilden, siehe dortige Begründung.
RUN docker-php-ext-install pdo_mysql ftp \
    && a2enmod rewrite headers expires

# Sicherheits-Härtung: keine Software-/Versionspreisgabe nach außen.
# - Apache: ServerTokens Prod (Banner nur "Apache", keine Version/OS),
#   ServerSignature Off, TraceEnable Off (HTTP TRACE aus → mitigiert XST).
#   Als zz-*.conf, damit es nach der Distributions-security.conf zuletzt greift
#   (bei ServerTokens gewinnt das letzte Vorkommen).
# - PHP: expose_php Off entfernt den X-Powered-By-Header (PHP-Version).
# Von einem DAST-Scan gefunden (security/), hier abgestellt.
RUN printf 'ServerTokens Prod\nServerSignature Off\nTraceEnable Off\n' \
      > /etc/apache2/conf-available/zz-hardening.conf \
    && a2enconf zz-hardening \
    && printf 'expose_php = Off\n' \
      > /usr/local/etc/php/conf.d/zz-hardening.ini

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
