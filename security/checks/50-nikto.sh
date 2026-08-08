#!/usr/bin/env bash
# Check: nikto — Web-Server-Schwachstellen und Fehlkonfigurationen.
#
# nikto ist breit, aber gegen eine App mit Catch-all-Routing (jede unbekannte
# URL landet per Front-Controller bei 200/302) neigt es zu Fehlalarmen ("Datei X
# existiert"). Deshalb:
#   - nikto-Funde sind LOW und blockieren das Gate NIE — sie sind ein Hinweis
#     zur manuellen Sichtung, nicht das harte Kriterium (dafuer stehen die
#     deterministischen Checks headers/exposed-paths/open-ports und sqli).
#   - Reine Statuszeilen/Boilerplate werden herausgefiltert.
#   - Bereits andernorts praeziser geprueftes (Header-Empfehlungen) faellt weg.
#   - baseline/nikto.allow blendet bekannte, bewertete Rausch-Funde aus.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$HERE/lib/common.sh"

if ! tool_available nikto; then
  record_finding INFO nikto "nikto nicht verfuegbar" "Check uebersprungen"
  exit 0
fi

ALLOW="$HERE/baseline/nikto.allow"
maxtime="${NIKTO_MAXTIME:-120}"

out="$(sec_tool nikto -host "$SCAN_HOST" -port "$SCAN_PORT" \
        -nointeractive -maxtime "${maxtime}s" -ask no -Tuning 123bde 2>/dev/null)"

if [[ -z "$out" ]]; then
  record_finding INFO nikto "nikto ohne Ausgabe" "evtl. Timeout"
  exit 0
fi

# Boilerplate/Statuszeilen, die keine Funde sind. Header-Empfehlungen bewusst
# raus: die deckt 10-http-headers.sh praeziser und HTTP/HTTPS-bewusst ab.
BOILER='Platform:|No CGI Directories|Scan terminated|item(s|s)? reported|items reported|[0-9]+ error|Target (IP|Hostname|Port)|Start Time|End Time|Server:|SSL Info|Root page|robots.txt|Suggested security header|Retrieved [a-z-]+ header|Uncommon header|Multiple index files|Cookie .* created|The site uses|host\(s\) tested|requests:'

mapfile -t findings < <(printf '%s\n' "$out" \
  | grep -E '^\+ ' \
  | grep -viE "$BOILER")

reported=0
for line in "${findings[@]}"; do
  msg="$(printf '%s' "$line" | sed -E 's/^\+ //; s/\s+/ /g')"
  [[ -z "$msg" ]] && continue
  if [[ -f "$ALLOW" ]] && printf '%s' "$msg" \
        | grep -qFf <(grep -vE '^\s*#|^\s*$' "$ALLOW"); then
    continue
  fi
  record_finding LOW nikto "$msg" "nikto-Fund — manuell verifizieren (Fehlalarm moeglich)"
  reported=$(( reported + 1 ))
done

if [[ "$reported" -eq 0 ]]; then
  record_finding PASS nikto "Keine nikto-Funde ausserhalb von Boilerplate/Allowlist"
fi
exit 0
