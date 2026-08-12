#!/usr/bin/env bash
# tests/e2e/spinup.sh  —  "aufbau" des E2E-Nachtlaufs.
#
# Fährt die REINE Umgebung hoch: Wegwerf-Backup-Ziele (MinIO/WebDAV/FTPS),
# mailpit (statt echtem Mailserver) und die App aus dem Repo-Dockerfile. Es
# wird hier NICHTS fachlich geprüft - scheitert etwas, gilt der Lauf als
# "nicht geprüft" (der devhost-tests-Läufer wertet einen aufbau-Fehler so).
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
E2E_DIR="$REPO_ROOT/tests/e2e"
NS="${TESTLAUF_NS:-hv-e2e}"
export E2E_NET="${NS}-net"
export E2E_CERT_DIR="$E2E_DIR/helpers/certs"
STATE="$E2E_DIR/.e2e-state"

echo "[e2e-aufbau] NS=$NS NET=$E2E_NET"

# 1. Netz
docker network create "$E2E_NET" >/dev/null 2>&1 || true

# 2. Mail-Cert (SAN=e2e-mail) - der Mailer prüft den Peer-Namen.
mkdir -p "$E2E_CERT_DIR"
if [[ ! -f "$E2E_CERT_DIR/cert.pem" ]]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
    -keyout "$E2E_CERT_DIR/cert.key" -out "$E2E_CERT_DIR/cert.pem" \
    -subj "/CN=e2e-mail" -addext "subjectAltName=DNS:e2e-mail" >/dev/null 2>&1
fi

# 3. Helfer hoch
docker compose -p "${NS}-helpers" -f "$E2E_DIR/helpers/docker-compose.yml" up -d --build

# 4. MinIO abwarten + Bucket anlegen
echo "[e2e-aufbau] MinIO/Bucket..."
for _ in $(seq 1 30); do
  if docker run --rm --network "$E2E_NET" --entrypoint sh minio/mc:latest -c \
      "mc alias set m http://e2e-minio:9000 minioadmin minio-e2e-testpass && mc mb -p m/hengst-backups" >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

# 5. App-.env (Auto-Provisionierung + eigene Wegwerf-DB)
DBPW="e2e-db-$(openssl rand -hex 6)"
ROOTPW="e2e-root-$(openssl rand -hex 6)"
APPKEY="$(openssl rand -hex 32)"
ADMINPW="E2eAdmin-$(openssl rand -hex 6)"
cat > "$REPO_ROOT/.env" <<EOF
DB_HOST=db
DB_PORT=3306
DB_NAME=hengstverzeichnis
DB_USER=hengst_user
DB_PASS=$DBPW
DB_ROOT_PASS=$ROOTPW
APP_KEY=$APPKEY
APP_ENV=production
SITE_NAME=E2E Nachtlauf
ADMIN_USERNAME=e2eadmin
ADMIN_EMAIL=e2e-admin@example.com
ADMIN_PASSWORD=$ADMINPW
EOF

# 6. App bauen + starten (Override: e2e-Netz + Loopback-Port)
#
# Abgeschirmt gegen geerbte DB_*-Variablen: docker compose gibt der
# Prozessumgebung Vorrang vor der soeben geschriebenen .env. Der nächtliche
# devhost-tests-Läufer exportiert für die PHP-Suiten DB_HOST/DB_PORT/DB_PASS
# und DB_USER=root — geerbt würde daraus MARIADB_USER=root, dessen
# CREATE USER 'root'@'%' die DB-Initialisierung mit ERROR 1396 abbrechen
# lässt (Issue #244). Maßgeblich soll allein die .env sein.
compose_app() {
  env -u DB_HOST -u DB_PORT -u DB_NAME -u DB_USER -u DB_PASS -u DB_ROOT_PASS \
    docker compose -p "${NS}-hv" \
    -f "$REPO_ROOT/docker-compose.yml" -f "$E2E_DIR/app-override.yml" "$@"
}
compose_app up -d --build

APP_CID="$(compose_app ps -q app)"

# Für run.sh/teardown.sh festhalten
cat > "$STATE" <<EOF
NS=$NS
E2E_NET=$E2E_NET
E2E_CERT_DIR=$E2E_CERT_DIR
APP_CID=$APP_CID
ADMIN_EMAIL=e2e-admin@example.com
ADMIN_PASSWORD=$ADMINPW
EOF

# 7. App bereit?
echo "[e2e-aufbau] warte auf App..."
ok=0
for _ in $(seq 1 40); do
  code="$(docker run --rm --network "$E2E_NET" curlimages/curl:latest \
          -s -o /dev/null -w '%{http_code}' http://hvapp/ 2>/dev/null || echo 000)"
  case "$code" in 200|301|302) echo "  App antwortet ($code)"; ok=1; break ;; esac
  sleep 3
done
[[ "$ok" == 1 ]] || { echo "[e2e-aufbau] App nicht erreichbar - Umgebung nicht bereit." >&2; exit 1; }

# 8. Mail-Cert im App-Container vertrauen
docker cp "$E2E_CERT_DIR/cert.pem" "$APP_CID:/usr/local/share/ca-certificates/e2e-mail.crt" >/dev/null 2>&1 || true
docker exec "$APP_CID" update-ca-certificates >/dev/null 2>&1 || true

echo "[e2e-aufbau] Umgebung bereit."
