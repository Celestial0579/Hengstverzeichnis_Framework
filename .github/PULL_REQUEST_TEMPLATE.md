## Was ändert sich, und warum

<!-- Was war vorher, was ist nachher, und was war der Anlass. Ein Satz zur
     Begründung ist mehr wert als eine Liste geänderter Dateien - die steht
     ohnehin im Diff. -->

Schließt #

## Beleg

<!-- Womit ist das geprüft? Testlauf, Zahlen, Vorher/Nachher. -->

## Prüfliste

- [ ] Testsuite läuft lokal grün
- [ ] `CHANGELOG.md` nachgezogen
- [ ] Schema-Änderungen liegen **sowohl** in `database/schema.sql` **als auch** im `SchemaMigrator` — sonst bekommt eine bestehende Installation sie beim Update nicht
- [ ] Öffentliche Ausgaben geprüft: keine personenbezogenen Daten, die `is_published` / `contact_public` nicht ausdrücklich freigeben
- [ ] Auswirkungen auf Addons bedacht (Hooks, Felder, Routen) — brechende Änderungen gehören ins [Addons-Repo](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues) gemeldet
- [ ] Neue Ansichten auf schmalen Bildschirmen geprüft (360 px): Tabellen in `<div class="tabelle-scroll">`, Raster über `.raster` statt fest gezählter Spalten, Bedienelemente mindestens 44 px hoch — Layout gehört in eine Klasse, ein `style`-Attribut kann keine Media Query tragen (#345)
- [ ] Dokumentation unter `docs/` nachgezogen, wenn sich Verhalten oder Erweiterungspunkte ändern
- [ ] Bei Sicherheitsbezug: **noch offene** Schwachstellen gehören nicht in diesen Text, sondern in ein [Security Advisory](../../security/advisories/new)
