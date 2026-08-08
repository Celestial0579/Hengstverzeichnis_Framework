#!/usr/bin/env bash
# Check: SQL-Injection auf der oeffentlichen Angriffsflaeche (sqlmap).
#
# Das Framework nutzt durchgaengig gebundene Parameter (Prepared Statements,
# siehe docs/security.md); dieser Check ist die dynamische Gegenprobe und faengt
# Regressionen, bei denen doch einmal interpoliert wird. sqlmap crawlt die
# oeffentlichen Formulare (Katalogsuche/Filter) und testet zusaetzlich die in
# baseline/sqli-targets.txt hinterlegten Parameter-URLs.
#
# Ein bestaetigter Injection-Punkt ist CRIT und blockiert das Release.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$HERE/lib/common.sh"

if ! tool_available sqlmap; then
  record_finding INFO sqli "sqlmap nicht verfuegbar" "Check uebersprungen"
  exit 0
fi

# Gemeinsame, defensive sqlmap-Optionen: nicht-interaktiv, knappe Laufzeit,
# frische Session, Ausgabe in einen Wegwerfpfad im Container.
COMMON=( --batch --level=1 --risk=1 --smart --technique=BEUST
         --timeout=10 --retries=1 --flush-session --disable-coloring
         --output-dir=/tmp/sqlmap-out -v0 )

vulnerable=0
scan_report() {
  local label="$1" out="$2"
  # WICHTIG: NICHT auf den Teilstring 'injectable' pruefen — der steckt auch in
  # den Negativmeldungen ("do not appear to be injectable", "might not be
  # injectable"). Positiver Beweis ist einzig sqlmaps Bestaetigungszeile
  # "identified the following injection point(s)" bzw. der Report-Block
  # "Parameter: … (GET/POST)". (Kostete einen Fehlalarm CRIT, siehe PR.)
  if printf '%s' "$out" | grep -qiE 'identified the following injection point' \
     || printf '%s' "$out" | grep -qE '^Parameter: .*\((GET|POST|Cookie|URI)\)'; then
    local pts; pts="$(printf '%s' "$out" | grep -iE '^Parameter:|^ *Type:|^ *Title:' \
                       | tr '\n' ' ' | sed -E 's/\s+/ /g' | cut -c1-300)"
    record_finding CRIT sqli "SQL-Injection bestaetigt: $label" "${pts:-sqlmap-Bestaetigung}"
    vulnerable=$(( vulnerable + 1 ))
  elif printf '%s' "$out" | grep -qiE 'do not appear to be injectable|all tested parameters|might not be injectable'; then
    record_finding PASS sqli "Keine SQL-Injection: $label"
  else
    record_finding INFO sqli "sqlmap ohne klares Ergebnis: $label" "evtl. Timeout/Fehler — manuell nachsehen"
  fi
}

# 1) Formular-Crawl der oeffentlichen Katalogseite (Tiefe 1).
out="$(sec_tool sqlmap -u "$SCAN_URL/katalog" --forms --crawl=1 \
        --crawl-exclude='logout|login|setup|admin' "${COMMON[@]}" 2>/dev/null)"
scan_report "/katalog (Formular-Crawl)" "$out"

# 2) Explizite Parameter-Ziele aus der Baseline (falls vorhanden).
TARGETS="$HERE/baseline/sqli-targets.txt"
if [[ -f "$TARGETS" ]]; then
  while IFS= read -r rel; do
    rel="${rel%%#*}"; rel="$(printf '%s' "$rel" | xargs 2>/dev/null)"
    [[ -z "$rel" ]] && continue
    out="$(sec_tool sqlmap -u "${SCAN_URL}${rel}" "${COMMON[@]}" 2>/dev/null)"
    scan_report "$rel" "$out"
  done < "$TARGETS"
fi

[[ "$vulnerable" -eq 0 ]] && record_finding INFO sqli "sqlmap-Lauf abgeschlossen, keine Injektion gefunden"

exit 0
