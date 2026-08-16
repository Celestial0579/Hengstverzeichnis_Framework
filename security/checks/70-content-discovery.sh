#!/usr/bin/env bash
# Check: Content-Discovery (gobuster) — was ist ausserhalb der bekannten Routen
# erreichbar? Ziel sind vergessene Backups, Test-/Admin-Endpunkte, Dumps.
#
# Gegen eine kleine, kuratierte Wortliste (wordlists/discovery.txt). Bewertung
# nach HTTP-Status UND Sensibilitaet des Namens:
#   - 200/204 mit Inhalt auf einem verdaechtigen Namen  -> MED (echter Fund)
#   - 200/204 auf unbekannter, unverfaenglicher Route     -> INFO
#   - 301/302/307/308 (Weiterleitung, z. B. /admin -> Login) -> INFO (geschuetzt)
# Blockierte Pfade (401/403) laesst gobuster hier weg — "blockiert" ist der
# gewuenschte Zustand und kein Fund. Erwartete App-Routen stehen in
# baseline/discovery.expected und werden uebersprungen.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$HERE/lib/common.sh"

WL="$HERE/wordlists/discovery.txt"
EXP="$HERE/baseline/discovery.expected"
[[ -f "$WL" ]] || { record_finding INFO content-discovery "Wortliste fehlt" "$WL"; exit 0; }

if ! tool_available gobuster; then
  record_finding INFO content-discovery "gobuster nicht verfuegbar" "Check uebersprungen"
  exit 0
fi

# Erwartete Routen einlesen und auf "/pfad" normalisieren (ohne Kommentare).
declare -A EXPECTED=()
if [[ -f "$EXP" ]]; then
  while IFS= read -r e; do
    e="${e%%#*}"; e="$(printf '%s' "$e" | xargs 2>/dev/null)"
    [[ -z "$e" ]] && continue
    [[ "$e" == /* ]] || e="/$e"
    EXPECTED["$e"]=1
  done < "$EXP"
fi

# 200/301/302/307/308 anzeigen; 401/403 (blockiert) bewusst nicht.
STATUSES="200,204,301,302,307,308"
hits=""
if [[ "$SEC_RUNNER" == "kali" ]]; then
  wlname="hvsec-wl-$$.txt"
  kali run bash -c "cat > /work/$wlname" < "$WL" >/dev/null 2>&1
  hits="$(sec_tool gobuster dir -u "$SCAN_URL" -w "/work/$wlname" \
            -q -k -t 20 --no-error --timeout 10s -s "$STATUSES" -b '' 2>/dev/null)"
  kali run bash -c "rm -f /work/$wlname" >/dev/null 2>&1 || true
else
  hits="$(sec_tool gobuster dir -u "$SCAN_URL" -w "$WL" \
            -q -k -t 20 --no-error --timeout 10s -s "$STATUSES" -b '' 2>/dev/null)"
fi

if [[ -z "$hits" ]]; then
  record_finding INFO content-discovery "gobuster ohne Treffer/Ausgabe"
  exit 0
fi

sensitive='backup|dump|\.sql|\.bak|\.old|~$|config|secret|admin|phpinfo|test|debug|\.zip|\.tar|\.gz|\.log'
findings=0
while IFS= read -r line; do
  [[ -z "$line" ]] && continue
  path="$(printf '%s' "$line" | awk '{print $1}')"
  [[ -z "$path" ]] && continue
  [[ "$path" == /* ]] || path="/$path"
  status="$(printf '%s' "$line" | grep -oE 'Status: *[0-9]+' | grep -oE '[0-9]+' | head -1)"
  [[ -n "${EXPECTED[$path]:-}" ]] && continue   # bekannte, erwartete Route

  case "$status" in
    200|204)
      if printf '%s' "$path" | grep -qiE "$sensitive"; then
        record_finding MED content-discovery "Verdaechtiger Pfad mit Inhalt: $path" "Status $status"
      else
        record_finding INFO content-discovery "Zusaetzliche Route mit Inhalt: $path" "Status $status"
      fi
      findings=$(( findings + 1 )) ;;
    301|302|307|308)
      record_finding INFO content-discovery "Weiterleitung (geschuetzt?): $path" "Status $status"
      findings=$(( findings + 1 )) ;;
    *)
      record_finding INFO content-discovery "$path" "Status $status"
      findings=$(( findings + 1 )) ;;
  esac
done <<< "$hits"

[[ "$findings" -eq 0 ]] && record_finding PASS content-discovery "Nur erwartete Routen mit Inhalt erreichbar"
exit 0
