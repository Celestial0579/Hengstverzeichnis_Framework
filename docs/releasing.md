# Releases

> **Automatisches Update (#85):** Installationen können neue Releases direkt
> im Admin-Bereich unter `/admin/updates` prüfen und einspielen
> (`App\Service\UpdateService`). Voraussetzung ist ein konfiguriertes
> automatisches Backup (#59) - vor jedem Update läuft zwingend ein
> Backup-Lauf; schlägt er fehl, wird das Update abgebrochen. Angewendet wird
> das unten beschriebene bereinigte Shared-Hosting-Zip; `config/db_config.php`,
> `public/uploads/`, `plugins/` und `.env` bleiben unangetastet, Migrationen
> laufen wie gewohnt beim nächsten Request (`Database::ensureSchemaUpToDate()`).
>
> **Docker/Container-Betrieb (In-Place-Update abgeschaltet):** Das
> In-Place-Update oben setzt voraus, dass der PHP-Prozess den Anwendungscode
> überschreiben darf - beim klassischen Shared-Hosting gehört der Code
> demselben Benutzer, unter dem PHP läuft (gleiche Vertrauensgrenze). Im
> offiziellen Docker-Image ist das anders: der Code gehört `root`, PHP läuft
> als `www-data`. Ein durch den Web-Prozess überschreibbarer Codebaum wäre ein
> RCE-Verstärker, deshalb setzt das Image `UPDATE_IN_PLACE=0` und macht nur die
> Datenverzeichnisse (`config/` per Sticky-Bit für `db_config.php`,
> `public/uploads/`, `plugins/`, `storage/`) www-data-schreibbar. Der
> Updates-Screen zeigt weiterhin an, ob ein neues Release vorliegt, bietet aber
> keinen In-Place-Knopf. Aktualisiert wird über ein **neues Image**
> (`docker compose pull && docker compose up -d`) - automatisierbar mit einem
> Watchtower-Fork (`nickfedor/watchtower`, Image
> `ghcr.io/nicholas-fedor/watchtower`), siehe den auskommentierten Dienst in
> `docker-compose.yml`.
>
> **Update-Kanäle:** Standard ist „Stabil" (nur reguläre Releases). Per
> Beta-Opt-in auf der Update-Seite (Setting `update_channel`) werden
> zusätzlich als **Prerelease** markierte GitHub-Releases angeboten — beim
> Veröffentlichen einer Vorabversion also das Prerelease-Häkchen setzen.
> In beiden Kanälen sind ausschließlich strikt neuere Versionen Kandidaten
> (`UpdateService::selectBestRelease()`): ein Downgrade ist ausgeschlossen,
> auch beim Wechsel von Beta zurück auf Stabil (die Installation bleibt dann
> auf der Beta-Version, bis ein neueres stabiles Release erscheint).

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
   - Bereinigtes Source-Zip für klassisches Shared-Hosting (ausgeschlossen
     sind `tests/`, `.github/`, `.claude/`, `composer.json`/`composer.lock`,
     `phpunit.xml` sowie die Docker-Dateien — die vollständige Liste steht
     im `git archive`-Aufruf in `release.yml`; `docs/` und `security/`
     bleiben bewusst enthalten) als
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
