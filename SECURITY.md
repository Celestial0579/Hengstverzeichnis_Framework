# Security Policy

Das Hengstverzeichnis Framework verwaltet personenbezogene Daten (Züchter,
Besitzer, Halter) und unterliegt daher besonderen Sorgfaltspflichten. Wir
nehmen Sicherheitsmeldungen ernst und bitten darum, sie **nicht** über
öffentliche Issues zu melden.

## Eine Sicherheitslücke melden

Bitte nutze **[GitHub Security Advisories](../../security/advisories/new)**
("Report a vulnerability"), um eine Schwachstelle vertraulich zu melden.
Das Team erhält die Meldung privat und kann sie koordiniert beheben, bevor
Details öffentlich werden.

Bitte gib nach Möglichkeit an:

- Betroffene Version/Commit
- Schritte zur Reproduktion (inkl. Beispiel-Request/Payload, falls zutreffend)
- Erwartetes vs. tatsächliches Verhalten
- Mögliche Auswirkungen (z. B. Zugriff auf personenbezogene Daten, Rechteausweitung)

## Was du erwarten kannst

- Eingangsbestätigung so schnell wie möglich
- Rückmeldung zur Einschätzung (bestätigt/nicht bestätigt) und geplantem
  weiteren Vorgehen
- Nennung als Melder in den Release Notes, sofern gewünscht — Details
  besprechen wir im Advisory

## Unterstützte Versionen

Da sich das Projekt aktuell in der Beta-Phase befindet, wird jeweils nur die
neueste veröffentlichte Version mit Sicherheitsupdates versorgt.

## Bereits umgesetzte Schutzmaßnahmen

Ein Überblick über das implementierte Sicherheitskonzept (2FA, Session-
Hardening, CSRF, Verschlüsselung, Rate-Limiting, Audit-Log u. a.) findet sich
in [docs/security.md](docs/security.md).
