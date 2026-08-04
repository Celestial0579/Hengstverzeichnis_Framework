#!/usr/bin/env bash
#
# Startet das Hengstverzeichnis Framework per Docker Compose.
# Legt beim ersten Start automatisch eine .env (aus .env.example) an und
# generiert DB_PASS/DB_ROOT_PASS/APP_KEY, falls diese noch Platzhalter sind.
#
# Nutzung:
#   ./docker-start.sh          Baut/startet die Container (Standard)
#   ./docker-start.sh down     Stoppt die Container
#   ./docker-start.sh logs     Zeigt die Logs des app-Containers
#
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
info()  { printf "${GREEN}==>${NC} %s\n" "$1"; }
warn()  { printf "${YELLOW}==>${NC} %s\n" "$1"; }
error() { printf "${RED}==>${NC} %s\n" "$1" >&2; }

require_compose() {
  if ! command -v docker >/dev/null 2>&1; then
    error "Docker wurde nicht gefunden. Installationsanleitung: https://docs.docker.com/get-docker/"
    exit 1
  fi
  if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
  elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE=(docker-compose)
  else
    error "Docker Compose wurde nicht gefunden (weder 'docker compose' noch 'docker-compose')."
    exit 1
  fi
}

random_hex() {
  local bytes="$1"
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex "$bytes"
  elif command -v php >/dev/null 2>&1; then
    php -r "echo bin2hex(random_bytes($bytes));"
  else
    error "Weder 'openssl' noch 'php' gefunden, um sichere Zufallswerte zu generieren."
    error "Bitte APP_KEY/DB_PASS manuell in .env eintragen."
    exit 1
  fi
}

# .bak-Datei wird von sed -i auf macOS/BSD zwingend benötigt, auf GNU optional
replace_placeholder() {
  local placeholder="$1" value="$2"
  sed -i.bak "s|^${placeholder}=.*|${placeholder}=${value}|" .env && rm -f .env.bak
}

ensure_env() {
  if [ -f .env ]; then
    info ".env existiert bereits, verwende bestehende Konfiguration."
    return
  fi

  info "Keine .env gefunden, erstelle sie aus .env.example ..."
  cp .env.example .env

  if grep -q '^DB_PASS=change-me$' .env; then
    replace_placeholder "DB_PASS" "$(random_hex 16)"
  fi
  if grep -q '^DB_ROOT_PASS=change-me-too$' .env; then
    replace_placeholder "DB_ROOT_PASS" "$(random_hex 16)"
  fi
  if grep -q '^APP_KEY=$' .env; then
    replace_placeholder "APP_KEY" "$(random_hex 32)"
  fi

  info "DB-Passwörter und APP_KEY wurden automatisch generiert und in .env gespeichert."
  warn "Optional: SITE_NAME/ADMIN_USERNAME/ADMIN_EMAIL/ADMIN_PASSWORD in .env setzen,"
  warn "um den Setup-Wizard komplett zu überspringen (siehe README, Abschnitt 'Ersteinrichtung ganz ohne Wizard')."
}

port_from_compose() {
  # Erwartet eine Zeile wie: - "8080:80" in docker-compose.yml
  grep -m1 -oE '"[0-9]+:80"' docker-compose.yml | grep -oE '^"[0-9]+' | tr -d '"' || echo "8080"
}

require_compose

case "${1:-up}" in
  down)
    "${COMPOSE[@]}" down
    ;;
  logs)
    "${COMPOSE[@]}" logs -f app
    ;;
  up)
    ensure_env
    info "Baue und starte Container (kann beim ersten Mal einige Minuten dauern) ..."
    "${COMPOSE[@]}" up -d --build
    PORT="$(port_from_compose)"
    echo
    info "Fertig! Die App ist erreichbar unter: http://localhost:${PORT}"
    info "Logs ansehen:  ./docker-start.sh logs"
    info "Stoppen:       ./docker-start.sh down"
    ;;
  *)
    error "Unbekanntes Kommando: $1 (erlaubt: up, down, logs)"
    exit 1
    ;;
esac
