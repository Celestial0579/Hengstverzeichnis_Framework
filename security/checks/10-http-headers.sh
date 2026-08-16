#!/usr/bin/env bash
# Check: HTTP-Sicherheits-Header & Cookie-Flags.
#
# Prueft die von der Anwendung ausgelieferte Blackbox-Sicht: Sind die im
# Sicherheitskonzept (docs/security.md, public/.htaccess) zugesagten Header
# tatsaechlich am laufenden Dienst gesetzt? Ein DAST-Lauf faengt hier genau die
# Faelle, in denen die .htaccess-Header im Deployment gar nicht greifen
# (z. B. AllowOverride/mod_headers fehlt) — was reine Code-Tests nicht sehen.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$HERE/lib/common.sh"

headers="$(http_head "$SCAN_URL/")"
if [[ -z "$headers" ]]; then
  record_finding HIGH headers "Startseite lieferte keine Header" "$SCAN_URL/ nicht erreichbar"
  exit 0
fi

# case-insensitiv suchen; Wert der ersten Trefferzeile zurueckgeben
hdr() { printf '%s' "$headers" | grep -iE "^$1:" | head -1 | sed -E 's/^[^:]+:[[:space:]]*//; s/\r$//'; }
has() { printf '%s' "$headers" | grep -qiE "^$1:"; }

is_https=0; [[ "$SCAN_URL" == https://* ]] && is_https=1

# --- Pflicht-/Soll-Header ----------------------------------------------------
if printf '%s' "$(hdr X-Content-Type-Options)" | grep -qi nosniff; then
  record_finding PASS headers "X-Content-Type-Options: nosniff gesetzt"
else
  record_finding MED headers "X-Content-Type-Options fehlt oder != nosniff" \
    "MIME-Sniffing-Schutz; in public/.htaccess vorgesehen"
fi

if has X-Frame-Options || printf '%s' "$(hdr Content-Security-Policy)" | grep -qi 'frame-ancestors'; then
  record_finding PASS headers "Clickjacking-Schutz (X-Frame-Options / CSP frame-ancestors) gesetzt"
else
  record_finding MED headers "Kein Clickjacking-Schutz" \
    "weder X-Frame-Options noch CSP frame-ancestors"
fi

if has Referrer-Policy; then
  record_finding PASS headers "Referrer-Policy gesetzt ($(hdr Referrer-Policy))"
else
  record_finding LOW headers "Referrer-Policy fehlt"
fi

if has Content-Security-Policy; then
  record_finding PASS headers "Content-Security-Policy vorhanden"
else
  record_finding LOW headers "Content-Security-Policy fehlt" \
    "kein CSP-Header — XSS-Restschutz nicht aktiv"
fi

if has Permissions-Policy; then
  record_finding PASS headers "Permissions-Policy gesetzt"
else
  record_finding INFO headers "Permissions-Policy fehlt"
fi

if [[ "$is_https" == "1" ]]; then
  if has Strict-Transport-Security; then
    record_finding PASS headers "HSTS gesetzt ($(hdr Strict-Transport-Security))"
  else
    record_finding MED headers "HSTS fehlt trotz HTTPS"
  fi
fi

# --- Informations-Leaks im Server-Banner -------------------------------------
srv="$(hdr Server)"
if printf '%s' "$srv" | grep -qE '[0-9]+\.[0-9]+'; then
  record_finding LOW headers "Server-Header nennt Versionsnummer" "Server: $srv"
else
  record_finding PASS headers "Server-Header ohne Versionsnummer${srv:+ ($srv)}"
fi
if has X-Powered-By; then
  record_finding LOW headers "X-Powered-By enthuellt Technologie/Version" "$(hdr X-Powered-By)"
else
  record_finding PASS headers "Kein X-Powered-By-Header"
fi

# --- Cookie-Flags ------------------------------------------------------------
# Eine Route, die zuverlaessig eine Session-Cookie setzt: die Login-Seite.
cookies="$(http_head "$SCAN_URL/login" | grep -iE '^set-cookie:')"
if [[ -z "$cookies" ]]; then
  cookies="$(printf '%s' "$headers" | grep -iE '^set-cookie:')"
fi
if [[ -n "$cookies" ]]; then
  while IFS= read -r c; do
    # if/else statt 'A && B || C': Bei dem Kurzmuster laeuft C auch dann,
    # wenn A wahr ist und B fehlschlaegt (SC2015). record_finding kann heute
    # nicht fehlschlagen - aber die Zusage haengt dann an einer Eigenschaft,
    # die niemand zugesichert hat.
    name="$(printf '%s' "$c" | sed -E 's/^[Ss]et-[Cc]ookie:[[:space:]]*([^=]+)=.*/\1/; s/\r$//')"
    if printf '%s' "$c" | grep -qi 'HttpOnly'; then
      record_finding PASS headers "Cookie '$name' HttpOnly"
    else
      record_finding MED headers "Cookie '$name' ohne HttpOnly" "per JS auslesbar"
    fi
    if printf '%s' "$c" | grep -qi 'SameSite'; then
      record_finding PASS headers "Cookie '$name' SameSite gesetzt"
    else
      record_finding LOW headers "Cookie '$name' ohne SameSite"
    fi
    if [[ "$is_https" == "1" ]]; then
      if printf '%s' "$c" | grep -qi 'Secure'; then
        record_finding PASS headers "Cookie '$name' Secure"
      else
        record_finding MED headers "Cookie '$name' ohne Secure trotz HTTPS"
      fi
    fi
  done <<< "$cookies"
else
  record_finding INFO headers "Keine Set-Cookie-Header beobachtet" \
    "Cookie-Flags nicht pruefbar (evtl. erst nach Login)"
fi

exit 0
