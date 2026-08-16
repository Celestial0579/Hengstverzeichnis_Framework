#!/usr/bin/env bash
#
# run-security-scan.sh — dynamischer Sicherheits-Scan (DAST) des Frameworks.
#
# Ergaenzt die statischen Pruefungen (PHPUnit, Semgrep) um eine Blackbox-Sicht
# von aussen: Es wird eine EPHEMERE Instanz aus dem AKTUELLEN Stand des Repos
# gebaut und gestartet, mit Kali-Werkzeugen gescannt und danach restlos wieder
# abgeraeumt. Gedacht als Gate vor jedem Release.
#
# WICHTIG — kein echter Dienst wird angefasst: Der Scan startet eine eigene
# Instanz in einem isolierten compose-Namensraum mit Wegwerf-Datenbank, gebunden
# an eine host-interne IP. Er zielt nie auf eine laufende Installation. (Lehre
# 'tests-ohne-schaden': ein Test, der ein echtes Ziel treffen KANN, richtet
# genau dann Schaden an, wenn der Schutz versagt.)
#
# Werkzeuge kommen ueber die Abstraktion in lib/common.sh:
#   - Devhost:  das 'kali'-Werkzeug (ephemere sys-kali-Container)
#   - lokal:    Werkzeuge direkt vom PATH
#   - CI:       ein Kali-Docker-Image  (SEC_RUNNER=docker)
#
# Aufruf:
#   security/run-security-scan.sh                 # bauen, starten, scannen, abraeumen
#   security/run-security-scan.sh --url URL       # externe, bereits laufende Instanz scannen
#   security/run-security-scan.sh --only headers,exposed-paths
#   security/run-security-scan.sh --strict        # MED-Funde blockieren ebenfalls
#   security/run-security-scan.sh --keep          # Instanz nach dem Scan stehen lassen (Debug)
#
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/lib/common.sh"

# ---------------------------------------------------------------------------
# Argumente
# ---------------------------------------------------------------------------
EXTERNAL_URL=""
STRICT=0
KEEP=0
ONLY=""
SKIP=""
COMPOSE_BIN=()

usage() {
  sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
  exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --url)     EXTERNAL_URL="${2:?}"; shift 2 ;;
    --url=*)   EXTERNAL_URL="${1#--url=}"; shift ;;
    --runner)  SEC_RUNNER="${2:?}"; shift 2 ;;
    --runner=*) SEC_RUNNER="${1#--runner=}"; shift ;;
    --only)    ONLY="${2:?}"; shift 2 ;;
    --only=*)  ONLY="${1#--only=}"; shift ;;
    --skip)    SKIP="${2:?}"; shift 2 ;;
    --skip=*)  SKIP="${1#--skip=}"; shift ;;
    --strict)  STRICT=1; shift ;;
    --keep)    KEEP=1; shift ;;
    -h|--help) usage 0 ;;
    *) err "unbekannte Option: $1"; usage 1 ;;
  esac
done

# ---------------------------------------------------------------------------
# Hilfsfunktionen fuers Hochfahren der Instanz
# ---------------------------------------------------------------------------
need() { command -v "$1" >/dev/null 2>&1 || { err "$1 nicht gefunden."; exit 1; }; }

resolve_compose() {
  if docker compose version >/dev/null 2>&1; then COMPOSE_BIN=(docker compose)
  elif command -v docker-compose >/dev/null 2>&1; then COMPOSE_BIN=(docker-compose)
  else err "Docker Compose nicht gefunden."; exit 1; fi
}

# Die Bridge-Gateway-IP ist vom Host UND von den (auf demselben Bridge-Netz
# laufenden) kali-Containern erreichbar — im Gegensatz zu 127.0.0.1, das im
# kali-Container auf ihn selbst zeigt. Fallback 172.17.0.1.
bridge_gateway() {
  local gw
  gw="$(docker network inspect bridge \
        -f '{{range .IPAM.Config}}{{.Gateway}}{{end}}' 2>/dev/null)"
  [[ -n "$gw" ]] && { echo "$gw"; return; }
  echo "172.17.0.1"
}

pick_free_port() {
  # Freien TCP-Port im hohen Bereich finden (ohne zusaetzliche Werkzeuge).
  local p
  for _ in $(seq 1 50); do
    p=$(( (RANDOM % 20000) + 20000 ))
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$p") 2>/dev/null; then echo "$p"; return; fi
    exec 3>&- 2>/dev/null || true
  done
  echo 28080
}

PROJECT=""
SCAN_ENV=""
# Wird per 'trap teardown EXIT INT TERM' aufgerufen (siehe unten) - shellcheck
# sieht indirekte Aufrufe nicht. Je nach Version meldet es dafuer SC2329
# ("function is never invoked") ODER SC2317 ("command appears to be
# unreachable") fuer den Rumpf; lokal war es SC2329, in der CI SC2317. Beide
# Codes stehen deshalb hier - eine Direktive, die nur die lokal sichtbare
# Fassung abdeckt, laesst das Gate anderswo rot.
# shellcheck disable=SC2329,SC2317
teardown() {
  [[ "$KEEP" == "1" ]] && { warn "Instanz bleibt stehen (--keep): Projekt '$PROJECT'"; return; }
  [[ -z "$PROJECT" ]] && return
  log "Raeume ephemere Instanz ab (Projekt '$PROJECT') …"
  SCAN_ENV="$SCAN_ENV" DB_PASS="${DB_PASS:-x}" \
    "${COMPOSE_BIN[@]}" -p "$PROJECT" -f "$HERE/compose.scan.yml" down -v --remove-orphans >/dev/null 2>&1 || true
  [[ -n "$SCAN_ENV" && -f "$SCAN_ENV" ]] && rm -f "$SCAN_ENV"
}

boot_app() {
  need docker; resolve_compose
  _resolve_runner

  # Bind-Host und Scan-Host: im kali/docker-Modus die Gateway-IP, sonst lokal.
  if [[ "$SEC_RUNNER" == "kali" || "$SEC_RUNNER" == "docker" ]]; then
    SCAN_HOST="$(bridge_gateway)"
  else
    SCAN_HOST="127.0.0.1"
  fi
  SCAN_PORT="$(pick_free_port)"
  SCAN_URL="http://${SCAN_HOST}:${SCAN_PORT}"
  PROJECT="${SCAN_PROJECT:-hvsec-$$-$RANDOM}"

  # Wegwerf-.env: zufaellige Geheimnisse, Setup-Wizard uebersprungen (alle vier
  # ADMIN_*-Werte gesetzt), production-Posture wie im Release.
  SCAN_ENV="$(mktemp /tmp/hvsec-env.XXXXXX)"
  local key pass rootpass adminpass
  key="$(openssl rand -hex 32 2>/dev/null || head -c32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  pass="$(openssl rand -hex 16 2>/dev/null || echo scanpass$RANDOM)"
  rootpass="$(openssl rand -hex 16 2>/dev/null || echo scanroot$RANDOM)"
  adminpass="Scan-$(openssl rand -hex 8 2>/dev/null || echo pass$RANDOM)!"
  export DB_PASS="$pass" DB_ROOT_PASS="$rootpass"
  cat >"$SCAN_ENV" <<EOF
DB_HOST=db
DB_PORT=3306
DB_NAME=hengstverzeichnis
DB_USER=hengst_user
DB_PASS=$pass
DB_ROOT_PASS=$rootpass
APP_KEY=$key
APP_ENV=production
SITE_NAME=Security Scan
ADMIN_USERNAME=scanadmin
ADMIN_EMAIL=scan@example.invalid
ADMIN_PASSWORD=$adminpass
EOF

  trap teardown EXIT INT TERM
  log "Baue und starte ephemere Instanz (Projekt '$PROJECT', Bind ${SCAN_HOST}:${SCAN_PORT}) …"
  if ! SCAN_BIND="$SCAN_HOST" SCAN_PORT="$SCAN_PORT" SCAN_ENV="$SCAN_ENV" \
       DB_PASS="$pass" DB_ROOT_PASS="$rootpass" \
       "${COMPOSE_BIN[@]}" -p "$PROJECT" -f "$HERE/compose.scan.yml" up -d --build 2>&1 | tail -20 >&2; then
    err "Instanz konnte nicht gestartet werden."; exit 1
  fi

  log "Warte auf HTTP-Bereitschaft unter $SCAN_URL …"
  local code=""
  for _ in $(seq 1 60); do
    code="$(http_code "$SCAN_URL/")"
    [[ "$code" =~ ^[23] ]] && { log "Instanz bereit (HTTP $code)."; return 0; }
    sleep 2
  done
  err "Instanz wurde nicht rechtzeitig erreichbar (letzter HTTP-Code: ${code:-keiner})."
  "${COMPOSE_BIN[@]}" -p "$PROJECT" -f "$HERE/compose.scan.yml" logs --tail 30 app >&2 2>/dev/null || true
  exit 1
}

# ---------------------------------------------------------------------------
# Ablauf
# ---------------------------------------------------------------------------
SEC_RESULTS="$(mktemp /tmp/hvsec-results.XXXXXX)"
export SEC_RESULTS SCAN_HOST SCAN_PORT SCAN_URL SEC_RUNNER SEC_DOCKER_IMAGE SEC_TOOL_TIMEOUT

if [[ -n "$EXTERNAL_URL" ]]; then
  # Externe, bereits laufende Instanz. Verantwortung liegt beim Aufrufer,
  # dass es ein autorisiertes, nicht-produktives Ziel ist.
  SCAN_URL="$EXTERNAL_URL"
  SCAN_HOST="$(printf '%s' "$EXTERNAL_URL" | sed -E 's#^[a-z]+://##; s#[:/].*$##')"
  SCAN_PORT="$(printf '%s' "$EXTERNAL_URL" | sed -nE 's#^[a-z]+://[^:/]+:([0-9]+).*#\1#p')"
  [[ -z "$SCAN_PORT" ]] && SCAN_PORT=80
  export SCAN_URL SCAN_HOST SCAN_PORT
  warn "Scanne externe Instanz: $SCAN_URL (kein Bauen/Abraeumen)."
else
  boot_app
fi

export SEC_PROJECT="${PROJECT:-}"
log "Werkzeug-Modus: $SEC_RUNNER   Ziel: $SCAN_URL"

# Checks in fester Reihenfolge. Jeder ist eigenstaendig ausfuehrbar und meldet
# seine Funde in SEC_RESULTS. Ausgewaehlt/ausgelassen ueber --only/--skip.
CHECKS=(
  "headers:10-http-headers.sh"
  "exposed-paths:20-exposed-paths.sh"
  "open-ports:30-open-ports.sh"
  "fingerprint:40-fingerprint.sh"
  "nikto:50-nikto.sh"
  "sqli:60-sqli.sh"
  "content-discovery:70-content-discovery.sh"
)

want() {
  local name="$1"
  if [[ -n "$ONLY" ]]; then [[ ",$ONLY," == *",$name,"* ]] || return 1; fi
  if [[ -n "$SKIP" ]]; then [[ ",$SKIP," == *",$name,"* ]] && return 1; fi
  return 0
}

for entry in "${CHECKS[@]}"; do
  name="${entry%%:*}"; file="${entry#*:}"
  want "$name" || { log "uebersprungen: $name (per --only/--skip)"; continue; }
  script="$HERE/checks/$file"
  [[ -f "$script" ]] || { warn "Check-Skript fehlt: $file"; continue; }
  printf '\n%s── Check: %s%s\n' "$C_BLU" "$name" "$C_NC" >&2
  # Per-Check-Zeitgrenze etwas ueber der Werkzeug-Grenze, damit ein einzelner
  # Check nie den ganzen Lauf blockiert.
  timeout $(( SEC_TOOL_TIMEOUT + 120 )) bash "$script" \
    || warn "Check '$name' endete mit Fehler/Timeout — Teilergebnisse zaehlen."
done

# ---------------------------------------------------------------------------
# Auswertung
# ---------------------------------------------------------------------------
ALLOW="$HERE/baseline/findings.allow"
is_allowlisted() {
  [[ -f "$ALLOW" ]] || return 1
  local key="$1"
  grep -vE '^\s*#|^\s*$' "$ALLOW" 2>/dev/null | while IFS= read -r pat; do
    [[ "$key" == *"$pat"* ]] && { echo hit; break; }
  done | grep -q hit
}

declare -A COUNT=( [CRIT]=0 [HIGH]=0 [MED]=0 [LOW]=0 [INFO]=0 [PASS]=0 [ACK]=0 )
blocking=0
printf '\n%s══ Zusammenfassung ══%s\n' "$C_BLU" "$C_NC" >&2
if [[ -s "$SEC_RESULTS" ]]; then
  while IFS='|' read -r sev check title _; do
    [[ -z "$sev" ]] && continue
    if [[ "$sev" =~ ^(CRIT|HIGH|MED)$ ]] && is_allowlisted "$check|$title"; then
      COUNT[ACK]=$(( COUNT[ACK] + 1 )); continue
    fi
    COUNT[$sev]=$(( ${COUNT[$sev]:-0} + 1 ))
    case "$sev" in
      CRIT|HIGH) blocking=$(( blocking + 1 )) ;;
      MED) [[ "$STRICT" == "1" ]] && blocking=$(( blocking + 1 )) ;;
    esac
  done < "$SEC_RESULTS"
fi

printf '  CRIT=%s HIGH=%s MED=%s LOW=%s INFO=%s  (PASS=%s, allowlisted=%s)\n' \
  "${COUNT[CRIT]}" "${COUNT[HIGH]}" "${COUNT[MED]}" "${COUNT[LOW]}" \
  "${COUNT[INFO]}" "${COUNT[PASS]}" "${COUNT[ACK]}" >&2

# Ergebnisdatei fuer CI/Weiterverarbeitung an einen stabilen Ort kopieren.
if [[ -n "${SEC_REPORT:-}" ]]; then cp "$SEC_RESULTS" "$SEC_REPORT" 2>/dev/null || true; fi

rc=0
if [[ "$blocking" -gt 0 ]]; then
  err "Gate FEHLGESCHLAGEN: $blocking blockierende Funde (CRIT/HIGH${STRICT:+/MED})."
  rc=2
else
  log "Gate bestanden: keine blockierenden Funde."
fi
rm -f "$SEC_RESULTS"
exit "$rc"
