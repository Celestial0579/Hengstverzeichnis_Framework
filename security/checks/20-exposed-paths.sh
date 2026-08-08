#!/usr/bin/env bash
# Check: exponierte sensible Pfade.
#
# Der Docroot ist public/ (siehe Dockerfile); src/, config/, database/ und die
# Repo-Wurzel liegen ausserhalb und duerfen ueber HTTP NICHT erreichbar sein.
# Ein falsch gesetzter Docroot oder fehlende .htaccess-Regeln wuerde genau das
# aufreissen — deshalb hier direkt am laufenden Dienst geprueft.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$HERE/lib/common.sh"

# pfad|schweregrad-bei-treffer|beschreibung
TARGETS=(
  "/.env|HIGH|Umgebungsdatei mit Geheimnissen"
  "/.env.example|LOW|Beispiel-Env (Struktur-Leak)"
  "/.git/config|HIGH|Git-Metadaten (Quellcode rekonstruierbar)"
  "/.git/HEAD|HIGH|Git-Metadaten"
  "/composer.json|MED|Abhaengigkeits-/Versionsinfo"
  "/composer.lock|MED|exakte Abhaengigkeitsversionen"
  "/docker-compose.yml|MED|Deployment-Interna"
  "/Dockerfile|LOW|Build-Interna"
  "/config/config.php|HIGH|Konfiguration/Quellcode"
  "/config/db_config.php|HIGH|DB-Zugangsdaten"
  "/database/schema.sql|MED|Datenbankschema"
  "/phpunit.xml|LOW|Test-Konfiguration"
  "/.htaccess|MED|Server-Konfiguration"
  "/src/|HIGH|Quellcode-Verzeichnis"
  "/src/Router.php|HIGH|Quellcode"
  "/vendor/|MED|Abhaengigkeiten"
  "/tests/|LOW|Testcode"
  "/README.md|LOW|Doku (Struktur-Leak)"
  "/CHANGELOG.md|LOW|Doku"
  "/uploads/|MED|Upload-Verzeichnis (Listing)"
  "/public/uploads/|MED|Upload-Verzeichnis (Listing)"
)

for entry in "${TARGETS[@]}"; do
  IFS='|' read -r path sev desc <<< "$entry"
  code="$(http_code "$SCAN_URL$path")"
  case "$code" in
    200|206)
      body="$(http_body "$SCAN_URL$path")"
      # Directory-Listing als eigener, klarer Fund
      if printf '%s' "$body" | grep -qiE 'Index of|<title>Index of'; then
        record_finding "$sev" exposed-paths "Directory-Listing offen: $path" "$desc"
      elif [[ -n "$body" ]]; then
        record_finding "$sev" exposed-paths "Erreichbar (HTTP 200): $path" "$desc"
      else
        record_finding LOW exposed-paths "HTTP 200 aber leer: $path" "$desc"
      fi
      ;;
    301|302|307|308)
      record_finding INFO exposed-paths "Weiterleitung ($code): $path" "vermutlich in die App gelenkt"
      ;;
    403) record_finding PASS exposed-paths "Blockiert (403): $path" ;;
    404|410) record_finding PASS exposed-paths "Nicht vorhanden ($code): $path" ;;
    000|"") record_finding INFO exposed-paths "Keine Antwort: $path" ;;
    *) record_finding INFO exposed-paths "HTTP $code: $path" "$desc" ;;
  esac
done

# Sonderfall: PHP-Quelltext darf nie im Klartext ausgeliefert werden.
idx="$(http_body "$SCAN_URL/index.php")"
if printf '%s' "$idx" | grep -q '<?php'; then
  record_finding CRIT exposed-paths "PHP wird als Quelltext ausgeliefert" \
    "/index.php enthaelt rohen <?php-Code — PHP-Handler greift nicht"
else
  record_finding PASS exposed-paths "PHP wird ausgefuehrt, nicht als Quelltext geliefert"
fi

exit 0
