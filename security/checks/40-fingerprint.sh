#!/usr/bin/env bash
# Check: Fingerprinting / Informations-Preisgabe (whatweb, nmap -sV, wafw00f).
#
# Ein Angreifer beginnt mit der Frage: Welche Software, welche Versionen? Je
# weniger der Dienst verraet, desto weniger gezielt laesst sich angreifen.
# Funde hier sind ueberwiegend LOW/INFO (Haertung, kein akutes Loch).
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$HERE/lib/common.sh"

# --- whatweb ----------------------------------------------------------------
if tool_available whatweb; then
  ww="$(sec_tool whatweb --color=never -a 3 "$SCAN_URL" 2>/dev/null)"
  if [[ -n "$ww" ]]; then
    record_finding INFO fingerprint "whatweb-Erkennung" "$(printf '%s' "$ww" | tr '\n' ' ' | cut -c1-300)"
    if printf '%s' "$ww" | grep -qiE 'Apache/[0-9]|Apache\[[0-9]'; then
      record_finding LOW fingerprint "Apache-Version im Banner erkennbar" "ServerTokens/ServerSignature haerten"
    fi
    if printf '%s' "$ww" | grep -qiE 'PHP/[0-9]|PHP\[[0-9]|X-Powered-By'; then
      record_finding LOW fingerprint "PHP-Version/-Praesenz preisgegeben" "expose_php=Off, X-Powered-By entfernen"
    fi
  else
    record_finding INFO fingerprint "whatweb ohne Ausgabe"
  fi
else
  record_finding INFO fingerprint "whatweb nicht verfuegbar"
fi

# --- nmap -sV (nur der Web-Port) --------------------------------------------
if tool_available nmap; then
  sv="$(sec_tool nmap -Pn -sV --version-light -p "$SCAN_PORT" "$SCAN_HOST" 2>/dev/null \
        | grep -E "^${SCAN_PORT}/tcp")"
  if [[ -n "$sv" ]]; then
    record_finding INFO fingerprint "nmap-Dienstbanner" "$(printf '%s' "$sv" | sed -E 's/\s+/ /g')"
    if printf '%s' "$sv" | grep -qE 'Apache httpd [0-9]|PHP [0-9]|/[0-9]+\.[0-9]+\.[0-9]+'; then
      record_finding LOW fingerprint "Versionsnummer im Dienstbanner" "$(printf '%s' "$sv" | sed -E 's/\s+/ /g')"
    fi
  fi
fi

# --- wafw00f (informativ) ---------------------------------------------------
if tool_available wafw00f; then
  wf="$(sec_tool wafw00f "$SCAN_URL" 2>/dev/null | grep -iE 'is behind|No WAF|seems to be behind' | head -1)"
  [[ -n "$wf" ]] && record_finding INFO fingerprint "WAF-Erkennung" "$(printf '%s' "$wf" | sed -E 's/\s+/ /g')"
fi

exit 0
