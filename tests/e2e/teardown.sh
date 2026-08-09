#!/usr/bin/env bash
# tests/e2e/teardown.sh  —  "abbau" des E2E-Nachtlaufs. Läuft immer, auch nach
# Abbruch. Räumt alle erzeugten Container/Volumes/Netze und temporären Dateien
# ab, damit nichts stehenbleibt.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
E2E_DIR="$REPO_ROOT/tests/e2e"
# shellcheck disable=SC1091
[[ -f "$E2E_DIR/.e2e-state" ]] && source "$E2E_DIR/.e2e-state"
NS="${NS:-${TESTLAUF_NS:-hv-e2e}}"
export E2E_NET="${E2E_NET:-${NS}-net}"
# Gegenüber dem docker-Wrapper als Eigentümer der ${NS}-* Container ausweisen,
# sonst kann der abbau die EIGENEN Container nicht entfernen (lehren.json).
[[ -n "${NS:-}" && -z "${ARBEIT_ID:-}" ]] && export ARBEIT_ID="$NS"

docker rm -f "${NS}-parcours" >/dev/null 2>&1 || true
docker compose -p "${NS}-hv" \
  -f "$REPO_ROOT/docker-compose.yml" -f "$E2E_DIR/app-override.yml" down -v >/dev/null 2>&1 || true
docker compose -p "${NS}-helpers" \
  -f "$E2E_DIR/helpers/docker-compose.yml" down -v >/dev/null 2>&1 || true
docker network rm "$E2E_NET" >/dev/null 2>&1 || true

rm -f "$REPO_ROOT/.env" 2>/dev/null || true
rm -rf "$E2E_DIR/helpers/certs" "$E2E_DIR/artefakte" "$E2E_DIR/.e2e-state" 2>/dev/null || true
echo "[e2e-abbau] abgeräumt."
