# Base-Image per Digest festgenagelt (Supply-Chain-Härtung, OpenSSF Scorecard
# "Pinned-Dependencies"). Der Tag bleibt lesbar dran; Dependabot (docker) hält
# den Digest aktuell, Diun meldet neue Tags weiterhin.
FROM php:8.5-apache@sha256:0b69594dd09a95f41b262a4fc03acc03da5b1ceda01dd33876f5226e90e19750

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

# Docroot direkt auf public/ setzen, damit src/, config/ und database/
# außerhalb des von Apache ausgelieferten Verzeichnisses liegen.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

COPY . .

# In-Place-Selbstaktualisierung im Container ABschalten: Anders als beim
# klassischen Shared-Hosting (dort besitzt der PHP-Benutzer den Code selbst)
# gehört der Code hier root, PHP läuft als www-data. Ein durch den Web-Prozess
# überschreibbarer Codebaum wäre ein RCE-Verstärker - deshalb wird der Code
# NICHT www-data-schreibbar gemacht und das In-Place-Update abgeschaltet.
# Aktualisiert wird im Container über ein neues Image (z. B. Watchtower); der
# Updates-Screen zeigt weiterhin an, dass ein neues Release vorliegt (#158).
ENV UPDATE_IN_PLACE=0

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
