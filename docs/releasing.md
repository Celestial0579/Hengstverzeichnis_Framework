# Releases

Release-**Notes** bleiben bewusst manuell kuratiert (siehe
[CHANGELOG.md](../CHANGELOG.md) und bestehende
[Releases](../../../releases)) – nur die **Artefakte** werden automatisch
gebaut, über [`.github/workflows/release.yml`](../.github/workflows/release.yml).

## Ablauf für Releases

1. [CHANGELOG.md](../CHANGELOG.md) um einen neuen Versionsabschnitt ergänzen
   (PR wie gewohnt).
2. Tag pushen (`vX.Y.Z`) auf `main`:
   ```bash
   git tag v0.2.0
   git push github v0.2.0
   ```
3. Das löst `release.yml` aus:
   - Volle Testsuite (Unit/Integration/Functional, siehe
     [development.md](development.md#tests)) als Gate – bricht ohne Release ab,
     falls etwas fehlschlägt.
   - Docker-Image aus dem [Dockerfile](../Dockerfile), gepusht nach
     `ghcr.io/celestial0579/hengstverzeichnis_framework` (Tags `<version>` +
     `latest`).
   - Bereinigtes Source-Zip für klassisches Shared-Hosting (ohne
     Dev-Tooling wie `tests/`, `composer.json`, `vendor/`, `.github/`) als
     Release-Asset.
4. Existiert für den Tag noch kein GitHub Release (z. B. weil nur der Tag
   gepusht wurde, ohne vorher über die GitHub-UI einen Release-Entwurf
   anzulegen), erstellt der Workflow einen mit angehängten Artefakten, aber
   **ohne Beschreibung** – dann Titel/Text im Nachgang manuell aus dem
   CHANGELOG-Abschnitt ergänzen (wie bei den bisherigen Releases, siehe
   [Release-Historie](../../../releases)).

## Einmalig: GHCR-Sichtbarkeit

GitHub Container Registry-Pakete, die über das automatische
`GITHUB_TOKEN` gepusht werden, sind standardmäßig **privat** – auch bei
einem öffentlichen Repository. Nach dem allerersten Release-Lauf einmalig
unter *Package Settings* auf **Public** stellen, damit
`docker pull ghcr.io/celestial0579/hengstverzeichnis_framework` ohne Login
funktioniert:

https://github.com/users/Celestial0579/packages/container/hengstverzeichnis_framework/settings
