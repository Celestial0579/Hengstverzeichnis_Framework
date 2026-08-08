#!/usr/bin/env bash
# Check: Port-Exposition der Instanz.
#
# Kernfrage: Wird versehentlich mehr veroeffentlicht als der Web-Port? Der
# haeufigste Fehler ist eine im compose offen gelegte Datenbank (3306). Das wird
# hier deterministisch ueber die Docker-Port-Mappings des Scan-Projekts geprueft
# (zuverlaessiger als ein nmap gegen die Gateway-IP, auf der auch Host-Dienste
# lauschen). Zusaetzlich bestaetigt nmap die Erreichbarkeit des Web-Ports —
# der Beweis, dass die kali-Werkzeugkette gegen das Ziel greift.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$HERE/lib/common.sh"

if [[ -n "${SEC_PROJECT:-}" ]] && command -v docker >/dev/null 2>&1; then
  # Alle Container des Scan-Projekts und ihre veroeffentlichten Ports.
  mapfile -t cids < <(docker ps -q --filter "label=com.docker.compose.project=$SEC_PROJECT" 2>/dev/null)
  for cid in "${cids[@]}"; do
    name="$(docker inspect -f '{{.Name}}' "$cid" 2>/dev/null | sed 's#^/##')"
    svc="$(docker inspect -f '{{index .Config.Labels "com.docker.compose.service"}}' "$cid" 2>/dev/null)"
    # Liste "hostip:hostport->containerport" der veroeffentlichten Ports
    published="$(docker inspect -f '{{range $p, $conf := .NetworkSettings.Ports}}{{range $conf}}{{.HostIp}}:{{.HostPort}}->{{$p}} {{end}}{{end}}' "$cid" 2>/dev/null)"
    if [[ "$svc" == "db" ]]; then
      if [[ -n "$published" ]]; then
        record_finding HIGH open-ports "Datenbank veroeffentlicht Ports" "$name: $published"
      else
        record_finding PASS open-ports "Datenbank veroeffentlicht keine Ports" "$name"
      fi
    else
      if [[ -n "$published" ]]; then
        record_finding INFO open-ports "Veroeffentlicht: $svc" "$published"
      fi
    fi
  done
else
  record_finding INFO open-ports "Kein Scan-Projekt bekannt" "Docker-Port-Pruefung uebersprungen (externe Instanz?)"
fi

# nmap-Bestaetigung des Web-Ports (kali). -Pn: kein Ping (Gateway antwortet evtl.
# nicht auf ICMP). Nur den einen bekannten Port, um Host-Dienste nicht mitzuscannen.
if tool_available nmap; then
  out="$(sec_tool nmap -Pn -p "$SCAN_PORT" --open "$SCAN_HOST" 2>/dev/null)"
  if printf '%s' "$out" | grep -qE "^${SCAN_PORT}/tcp\s+open"; then
    record_finding PASS open-ports "Web-Port ${SCAN_PORT} offen (nmap bestaetigt Erreichbarkeit)"
  else
    record_finding INFO open-ports "nmap sah Web-Port ${SCAN_PORT} nicht als offen" \
      "evtl. Firewall/Timing; HTTP-Checks liefen dennoch"
  fi
else
  record_finding INFO open-ports "nmap nicht verfuegbar" "Port-Bestaetigung uebersprungen"
fi

exit 0
