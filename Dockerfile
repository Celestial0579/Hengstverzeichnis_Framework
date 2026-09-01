# Bau-Stufe fuer die Laufzeit-Abhaengigkeiten (#353). Seit den Passkeys braucht
# die Anwendung web-auth/webauthn-lib; vendor/ entsteht hier frisch aus dem
# Lock, statt vom Entwicklerrechner mitgeschleppt zu werden (siehe
# .dockerignore). Composer-Image ebenfalls per Digest festgenagelt.
#
# --no-dev: Die Testsuite gehoert nicht ins Auslieferungs-Image.
# --classmap-authoritative: schaltet den PSR-4-Fallback ab, damit Klassen aus
#   einer abgeloesten Bibliotheksfassung nicht auf Zuruf ladbar bleiben.
FROM composer:2@sha256:d020706319701a44468968321dccd0fce6620190159a7a9ec195d78e6e971c71 AS deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --classmap-authoritative \
    --no-interaction --no-progress --no-scripts

# Base-Image per Digest festgenagelt (Supply-Chain-Härtung, OpenSSF Scorecard
# "Pinned-Dependencies"). Der Tag bleibt lesbar dran; Dependabot (docker) hält
# den Digest aktuell, Diun meldet neue Tags weiterhin.
FROM php:8.5-apache@sha256:c9b6cccfd92473ce7c80ba15a52767ce403a21a6af8849296b6f23799bf2221f

# ftp: für App\Service\FtpsClient (#93, FTPS als Backup-Ziel) - das
# FTP-Protokoll selbst lässt sich anders als S3/WebDAV nicht über PHP-Streams
# nachbilden, siehe dortige Begründung. ftp_ssl_connect() (also FTPS statt
# Klartext-FTP) entsteht nur, wenn die Extension MIT OpenSSL gebaut wird -
# dafür muss libssl-dev zur Bauzeit da sein. Ohne das war FTPS im Image nicht
# nutzbar, obwohl der Client es anbietet (#157).
# Bei einem phpize-Build (docker-php-ext-install) ist FTP-SSL laut ext/ftp/
# config.m4 standardmäßig AUS und muss mit --with-ftp-ssl explizit eingeschaltet
# werden (dafür libssl-dev/pkg-config zur Bauzeit). Ohne das fehlte
# ftp_ssl_connect() und FTPS war im Image nicht nutzbar (#157). Die
# Build-Abhängigkeiten werden danach wieder entfernt; das Laufzeit-libssl bleibt.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libssl-dev pkg-config \
    && docker-php-ext-configure ftp --with-ftp-ssl \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql ftp \
    && apt-get purge -y --auto-remove libssl-dev pkg-config \
    && rm -rf /var/lib/apt/lists/* \
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

# Laufzeit-Einstellungen.
#
# NUR die Upload-Grenzen, und das mit Absicht: Ein erster Entwurf setzte hier
# auch OPcache-Werte, weil der Prüfbericht "OPcache nicht aktiviert" meldete.
# Am laufenden Basis-Image nachgemessen stimmt das nicht - in php:8.5-apache
# ist OPcache fest einkompiliert (kein ladbares Modul, ein
# 'docker-php-ext-enable opcache' bricht den Build ab) und für die Web-SAPI
# bereits eingeschaltet:
#
#   opcache.enable                1
#   opcache.memory_consumption    128
#   opcache.max_accelerated_files 10000
#   opcache.revalidate_freq       2
#
# Die gesetzten Werte waren also entweder wirkungslos oder schädlich. Was
# bleibt, ist der echte Fund:
#
#   upload_max_filesize   2M   <- unter der 5-MB-Grenze der Anwendung
#   post_max_size         8M
#
# Ein 4-MB-Bild verwarf PHP, bevor der Code es je sah: $_FILES kam leer an,
# und der Benutzer las "keine Datei ausgewählt". Die Grenze der Anwendung ist
# die verbindliche, PHP muss darüber liegen. memory_limit und
# max_execution_time bleiben unangetastet - ein Zeitlimit von 60 Sekunden
# hätte Sicherung, Import und Update abgeschnitten.
RUN { \
      echo 'upload_max_filesize=8M'; \
      echo 'post_max_size=12M'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

# Docroot direkt auf public/ setzen, damit src/, config/ und database/
# außerhalb des von Apache ausgelieferten Verzeichnisses liegen.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

COPY . .

# vendor/ aus der Bau-Stufe, nicht aus dem Kontext (#353).
COPY --from=deps /app/vendor ./vendor

# In-Place-Selbstaktualisierung im Container ABschalten: Anders als beim
# klassischen Shared-Hosting (dort besitzt der PHP-Benutzer den Code selbst)
# gehört der Code hier root, PHP läuft als www-data. Ein durch den Web-Prozess
# überschreibbarer Codebaum wäre ein RCE-Verstärker - deshalb wird der Code
# NICHT www-data-schreibbar gemacht und das In-Place-Update abgeschaltet.
# Aktualisiert wird im Container über ein neues Image (z. B. Watchtower); der
# Updates-Screen zeigt weiterhin an, dass ein neues Release vorliegt (#158).
ENV UPDATE_IN_PLACE=0

# Ein Image ist immer eine echte Installation, nie ein Entwickler-Checkout.
# config/config.php leitet die Betriebsart inzwischen auch aus gesetzten
# DB_*-Variablen ab; hier steht sie zusätzlich ausdrücklich, damit sie nicht
# von einer Konfigurationsvariante abhängt. Wer im Container bewusst
# entwickeln will, überschreibt APP_ENV in seiner .env.
ENV APP_ENV=production

# Nur die DATEN-/Laufzeitverzeichnisse für www-data beschreibbar machen -
# der Code bleibt root und ist für den Web-Prozess nicht überschreibbar.
#   public/uploads  Bild-Uploads          - reine Daten, rekursiv www-data
#   plugins/        Addon-Store (admin-gated) kopiert Addons hierher
#   storage/        Logs und temporäre Dateien
RUN mkdir -p plugins storage \
    && chown -R www-data:www-data public/uploads plugins storage \
    && chmod -R u+rwX public/uploads plugins storage

# config/ ist ein Sonderfall: der Setup-Wizard muss hier db_config.php ANLEGEN,
# aber config/config.php ist CODE und darf NICHT überschreibbar werden. Lösung:
# das Verzeichnis gehört ROOT (Gruppe www-data, gruppen-schreibbar) plus
# Sticky-Bit. Über die Gruppe darf www-data neue Dateien anlegen; das Sticky-Bit
# erlaubt das Löschen/Ersetzen aber nur dem jeweiligen DateiEIGENTÜMER (oder dem
# Verzeichnis-Eigentümer root) - www-data ist bei config.php weder das eine noch
# das andere und kann sie daher weder überschreiben noch löschen, nur lesen. So
# läuft der Wizard, ohne dass ein www-data-Schreibprimitiv den Kern-Code
# austauschen könnte. (Verzeichnis-Eigentum bei www-data hätte genau das
# unterlaufen - der Verzeichnis-Eigentümer darf trotz Sticky-Bit löschen.)
RUN chown root:www-data config \
    && chmod 1775 config \
    && chown root:root config/config.php \
    && chmod 0644 config/config.php
