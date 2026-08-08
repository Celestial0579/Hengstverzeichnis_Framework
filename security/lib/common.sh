# shellcheck shell=bash
# security/lib/common.sh — gemeinsame Basis fuer alle Sicherheits-Checks.
#
# Wird von run-security-scan.sh und von jedem einzelnen Check unter checks/
# eingebunden (source). Enthaelt:
#   - die Werkzeug-Abstraktion sec_tool() (kali-Wrapper | lokal | Docker-Image)
#   - das Melden von Funden (record_finding) in die gemeinsame Ergebnisdatei
#   - kleine HTTP-Helfer (curl gegen die zu pruefende Instanz)
#
# Bewusst kein `set -e`: Ein einzelner fehlschlagender Scan darf den Lauf nicht
# abbrechen — jeder Check meldet seinen Fund und der Orchestrator entscheidet am
# Ende ueber den Ausgang. `set -u`/`pipefail` bleiben aktiv.
set -uo pipefail

# --- Konfiguration aus der Umgebung (vom Orchestrator gesetzt) ----------------
# SCAN_HOST/SCAN_PORT:  Adresse, unter der die Instanz fuer den Scanner UND fuer
#                       den Host erreichbar ist (im kali-Modus die Bridge-Gateway-IP,
#                       lokal 127.0.0.1). SCAN_URL wird daraus abgeleitet.
# SEC_RUNNER:           kali | local | docker  — wie Werkzeuge ausgefuehrt werden.
# SEC_RESULTS:          Datei, in die Funde (pipe-getrennt) angehaengt werden.
: "${SCAN_HOST:=127.0.0.1}"
: "${SCAN_PORT:=8080}"
: "${SCAN_URL:=http://${SCAN_HOST}:${SCAN_PORT}}"
: "${SEC_RUNNER:=auto}"
: "${SEC_RESULTS:=/tmp/hvsec-results.$$}"
: "${SEC_DOCKER_IMAGE:=kalilinux/kali-rolling}"
# Zeitgrenzen (Sekunden) — halten haengende Scans in Schach.
: "${SEC_TOOL_TIMEOUT:=300}"

# --- Farben nur, wenn stderr ein Terminal ist --------------------------------
if [[ -t 2 ]]; then
  C_RED=$'\033[0;31m'; C_YEL=$'\033[1;33m'; C_GRN=$'\033[0;32m'
  C_BLU=$'\033[0;34m'; C_DIM=$'\033[2m'; C_NC=$'\033[0m'
else
  C_RED=""; C_YEL=""; C_GRN=""; C_BLU=""; C_DIM=""; C_NC=""
fi

log()  { printf '%s==>%s %s\n' "$C_BLU" "$C_NC" "$*" >&2; }
warn() { printf '%s==>%s %s\n' "$C_YEL" "$C_NC" "$*" >&2; }
err()  { printf '%s==>%s %s\n' "$C_RED" "$C_NC" "$*" >&2; }

# --- Werkzeug-Auswahl --------------------------------------------------------
# Wird einmal bestimmt und gecacht. auto: 'kali' bevorzugen (Devhost), sonst das
# Werkzeug direkt vom PATH, sonst ein Kali-Docker-Image.
_resolve_runner() {
  [[ "${_SEC_RUNNER_RESOLVED:-}" == "1" ]] && return 0
  if [[ "$SEC_RUNNER" == "auto" ]]; then
    if command -v kali >/dev/null 2>&1; then SEC_RUNNER=kali
    else SEC_RUNNER=local
    fi
  fi
  _SEC_RUNNER_RESOLVED=1
}

# tool_available <name> — ist das Scan-Werkzeug in der gewaehlten Umgebung da?
tool_available() {
  local t="$1"; _resolve_runner
  case "$SEC_RUNNER" in
    kali)  kali run bash -c "command -v $t" >/dev/null 2>&1 ;;
    local) command -v "$t" >/dev/null 2>&1 ;;
    docker) command -v docker >/dev/null 2>&1 ;;
  esac
}

# sec_tool <tool> [args …] — fuehrt ein Scan-Werkzeug aus, Ausgabe auf stdout.
# Immer mit Zeitgrenze umschlossen; stdin wird durchgereicht (fuer Wortlisten).
sec_tool() {
  local tool="$1"; shift
  _resolve_runner
  case "$SEC_RUNNER" in
    kali)   timeout "$SEC_TOOL_TIMEOUT" kali run "$tool" "$@" ;;
    local)  timeout "$SEC_TOOL_TIMEOUT" "$tool" "$@" ;;
    docker) timeout "$SEC_TOOL_TIMEOUT" docker run --rm -i \
              --cap-drop ALL --cap-add NET_RAW --cap-add NET_ADMIN \
              "$SEC_DOCKER_IMAGE" "$tool" "$@" ;;
  esac
}

# --- Funde melden ------------------------------------------------------------
# record_finding <SEV> <check> <titel> [detail]
#   SEV: CRIT | HIGH | MED | LOW | INFO | PASS
# Eine Zeile je Fund, pipe-getrennt, in SEC_RESULTS. Der Orchestrator wertet aus.
record_finding() {
  local sev="$1" check="$2" title="$3" detail="${4:-}"
  # Pipes im Text neutralisieren, damit das Trennzeichen eindeutig bleibt.
  title="${title//|//}"; detail="${detail//|//}"
  printf '%s|%s|%s|%s\n' "$sev" "$check" "$title" "$detail" >>"$SEC_RESULTS"
  local col="$C_DIM"
  case "$sev" in
    CRIT|HIGH) col="$C_RED" ;; MED) col="$C_YEL" ;;
    PASS) col="$C_GRN" ;; LOW|INFO) col="$C_DIM" ;;
  esac
  printf '   %s[%-4s]%s %s%s\n' "$col" "$sev" "$C_NC" "$title" \
    "${detail:+ ${C_DIM}— ${detail}${C_NC}}" >&2
}

# --- HTTP-Helfer (laufen auf dem Host, gegen SCAN_URL) -----------------------
# Der Host erreicht SCAN_URL in beiden Modi (er besitzt die Gateway-IP bzw.
# 127.0.0.1), daher genuegt curl vom Host aus fuer die deterministischen Checks.
http_code()  { curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "$@" 2>/dev/null; }
http_head()  { curl -sS -D - -o /dev/null --max-time 15 "$@" 2>/dev/null; }
http_body()  { curl -sS --max-time 15 "$@" 2>/dev/null; }
