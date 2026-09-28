#!/usr/bin/env bash
# tests/docker/image-smoke.sh - Rauchtest fuer das offizielle Docker-Image
# (Audit M31, N38, N41).
#
# Prueft am GEBAUTEN Image, was sich statisch nicht pruefen laesst:
#   - Apache-Konfiguration gueltig (apache2ctl -t), ${APACHE_DOCUMENT_ROOT}
#     wird in docker/apache-uploads.conf aufgeloest.
#   - HV_CONTAINER=1 gesetzt, VOLUME /var/www/html/storage/horses deklariert.
#   - www-data kann var/ beschreiben (Wartungs-Marker, var/datenmigration).
#   - public/uploads/horses/.htaccess liegt im Image.
#   - App\Helper\ContainerAblage::art(): ohne Volume anonymes_volume, mit
#     benanntem Volume und mit Bind-Mount eigener_mount.
#   - Ein ALT-Volume mit der .htaccess aus 0.7.x, einer eingeschleusten
#     branding/.htaccess, einem Altfoto und einer shell.php: Logo 200 mit CORP
#     und ohne PHP-Ausfuehrung, /uploads/horses/* 403 (auch nicht existierende
#     Dateien), shell.php 403, .htaccess 403.
#
# Aufruf aus dem Repository-Wurzelverzeichnis:
#   tests/docker/image-smoke.sh              # baut das Image selbst
#   IMAGE=hv:test tests/docker/image-smoke.sh # nimmt ein vorhandenes Image
# Optional: SMOKE_PORT (Standard 18080). Braucht docker und curl, keine
# Datenbank. Raeumt Container, Volumes und Temp-Verzeichnisse per trap auf;
# ein selbst gebautes Image ebenfalls.
set -euo pipefail

cd "$(dirname "$0")/../.."

PORT="${SMOKE_PORT:-18080}"
KENNUNG="hvsmoke$$"
EIGENES_IMAGE=0
if [ -z "${IMAGE:-}" ]; then
    IMAGE="hv-smoke:${KENNUNG}"
    EIGENES_IMAGE=1
fi
VOL_ALT="${KENNUNG}_uploads"
VOL_FOTOS="${KENNUNG}_horses"
CONTAINER="${KENNUNG}_app"
TMPDIR_SMOKE="$(mktemp -d)"
FEHLER=0

aufraeumen() {
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
    docker volume rm -f "$VOL_ALT" "$VOL_FOTOS" >/dev/null 2>&1 || true
    if [ "$EIGENES_IMAGE" = 1 ]; then
        docker image rm -f "$IMAGE" >/dev/null 2>&1 || true
    fi
    rm -rf "$TMPDIR_SMOKE"
}
trap aufraeumen EXIT

ok() { printf 'ok   %s\n' "$1"; }
fehler() { printf 'FEHL %s\n' "$1" >&2; FEHLER=1; }
pruefe() {
    # pruefe <Beschreibung> <Befehl...>
    local beschreibung="$1"
    shift
    if "$@"; then ok "$beschreibung"; else fehler "$beschreibung"; fi
}

# Laeuft einen Befehl im Image, ohne den Entrypoint (Apache) zu starten.
im_image() {
    docker run --rm --entrypoint "$@"
}

if [ "$EIGENES_IMAGE" = 1 ]; then
    docker build -t "$IMAGE" .
fi

# --- Aufbau des Images ------------------------------------------------------

pruefe "apache2ctl -t" im_image apache2ctl "$IMAGE" -t
pruefe "zz-uploads aktiviert" im_image test "$IMAGE" -L /etc/apache2/conf-enabled/zz-uploads.conf
# shellcheck disable=SC2016 # soll erst im Container expandieren
pruefe "HV_CONTAINER=1" im_image sh "$IMAGE" -c 'test "$HV_CONTAINER" = 1'
volumes="$(docker image inspect -f '{{json .Config.Volumes}}' "$IMAGE")"
pruefe "VOLUME storage/horses deklariert ($volumes)" grep -q '"/var/www/html/storage/horses"' <<<"$volumes"
pruefe "horses/.htaccess im Image" im_image test "$IMAGE" -f /var/www/html/public/uploads/horses/.htaccess
# shellcheck disable=SC2016 # soll erst im Container expandieren
pruefe "keine Laufzeitreste in var/" im_image sh "$IMAGE" -c 'test -z "$(ls -A var | grep -v "^.gitkeep$")"'

# --- N38: var/ fuer www-data beschreibbar ------------------------------------

pruefe "www-data schreibt var/datenmigration" \
    im_image sh --user www-data "$IMAGE" -c \
    'mkdir -p var/datenmigration && echo probe > var/datenmigration/probe && rm -r var/datenmigration'
pruefe "www-data setzt den Wartungsmodus" \
    im_image php --user www-data "$IMAGE" -r \
    'require "src/Service/Maintenance.php"; App\Service\Maintenance::enable("Rauchtest"); exit(App\Service\Maintenance::isActive() ? 0 : 1);'

# --- M31: Erkennung der Ablage -----------------------------------------------

art_code='require "src/Helper/ContainerAblage.php"; echo App\Helper\ContainerAblage::art("/var/www/html/storage/horses");'
art="$(im_image php "$IMAGE" -r "$art_code")"
pruefe "ohne Volume: anonymes_volume (ist: $art)" test "$art" = anonymes_volume
docker volume create "$VOL_FOTOS" >/dev/null
art="$(docker run --rm -v "$VOL_FOTOS:/var/www/html/storage/horses" --entrypoint php "$IMAGE" -r "$art_code")"
pruefe "benanntes Volume: eigener_mount (ist: $art)" test "$art" = eigener_mount
mkdir -p "$TMPDIR_SMOKE/horses"
art="$(docker run --rm -v "$TMPDIR_SMOKE/horses:/var/www/html/storage/horses" --entrypoint php "$IMAGE" -r "$art_code")"
pruefe "Bind-Mount: eigener_mount (ist: $art)" test "$art" = eigener_mount

# --- N41: Alt-Volume aus 0.7.x -----------------------------------------------

docker volume create "$VOL_ALT" >/dev/null
docker run --rm --user root \
    -v "$VOL_ALT:/var/www/html/public/uploads" \
    -v "$PWD/tests/docker/fixtures:/fixtures:ro" \
    --entrypoint sh "$IMAGE" -c '
        set -e
        cd /var/www/html/public/uploads
        cp /fixtures/uploads-0.7.x.htaccess .htaccess
        rm -f horses/.htaccess
        mkdir -p branding horses
        printf "PNGDATEN <?php echo \"PHP-\" . \"LAEUFT\"; ?>" > branding/logo.png
        printf "AddHandler application/x-httpd-php .png\n" > branding/.htaccess
        printf "ALTFOTO" > horses/alt.jpg
        printf "<?php echo \"SHELL-\" . \"LAEUFT\"; ?>" > shell.php
        chown -R www-data:www-data .
    '

docker run -d --name "$CONTAINER" -p "127.0.0.1:${PORT}:80" \
    -v "$VOL_ALT:/var/www/html/public/uploads" "$IMAGE" >/dev/null

basis="http://127.0.0.1:${PORT}"
for _ in $(seq 1 30); do
    if curl -s -o /dev/null "$basis/uploads/branding/logo.png"; then
        break
    fi
    sleep 1
done

status() {
    curl -s -o "$TMPDIR_SMOKE/body" -D "$TMPDIR_SMOKE/kopf" -w '%{http_code}' "$@" || true
}

code="$(status -H "Referer: $basis/" "$basis/uploads/branding/logo.png")"
pruefe "Logo mit eigenem Referer: 200 (ist: $code)" test "$code" = 200
pruefe "Logo mit CORP same-origin" grep -qi '^cross-origin-resource-policy: same-origin' "$TMPDIR_SMOKE/kopf"
pruefe "Logo nicht als PHP ausgefuehrt" grep -q '<?php' "$TMPDIR_SMOKE/body"
if grep -q 'PHP-LAEUFT' "$TMPDIR_SMOKE/body"; then fehler "eingeschleuste branding/.htaccess wirkt"; else ok "eingeschleuste branding/.htaccess wirkungslos"; fi

for pfad in /uploads/horses/alt.jpg /uploads/horses/gibtsnicht.jpg /uploads/shell.php /uploads/.htaccess /uploads/branding/.htaccess; do
    code="$(status "$basis$pfad")"
    pruefe "$pfad: 403 (ist: $code)" test "$code" = 403
    if grep -q 'LAEUFT' "$TMPDIR_SMOKE/body"; then fehler "$pfad wurde ausgefuehrt"; fi
done

if [ "$FEHLER" != 0 ]; then
    echo "--- Apache-Log ---" >&2
    docker logs "$CONTAINER" 2>&1 | tail -n 50 >&2 || true
    echo "Rauchtest FEHLGESCHLAGEN" >&2
    exit 1
fi
echo "Rauchtest bestanden"
