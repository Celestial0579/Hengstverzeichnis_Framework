# Security Policy

Das Hengstverzeichnis Framework verwaltet personenbezogene Daten (Züchter,
Besitzer, Halter) und unterliegt daher besonderen Sorgfaltspflichten. Wir
nehmen Sicherheitsmeldungen ernst und bitten darum, sie **nicht** über
öffentliche Issues zu melden.

## Eine Sicherheitslücke melden

Bitte nutze **[GitHub Security Advisories](https://github.com/Celestial0579/Hengstverzeichnis_Framework/security/advisories/new)**
("Report a vulnerability"), um eine Schwachstelle vertraulich zu melden.
Das Team erhält die Meldung privat und kann sie koordiniert beheben, bevor
Details öffentlich werden. Betrifft der Fund ein Addon statt des Kerns, melde
ihn bitte im
[Addons-Repository](https://github.com/Celestial0579/Hengstverzeichnis_Addons/security/advisories/new).

Bitte gib nach Möglichkeit an:

- Betroffene Version/Commit
- Schritte zur Reproduktion (inkl. Beispiel-Request/Payload, falls zutreffend)
- Erwartetes vs. tatsächliches Verhalten
- Mögliche Auswirkungen (z. B. Zugriff auf personenbezogene Daten, Rechteausweitung)

## Was du erwarten kannst

Die folgenden Fristen sind Richtwerte eines klein besetzten Projekts, keine
vertraglichen Zusagen — wir melden uns, wenn eine davon nicht zu halten ist:

- **Eingangsbestätigung:** innerhalb von 3 Werktagen
- **Erste Einschätzung** (bestätigt/nicht bestätigt, Schweregrad, geplantes
  Vorgehen): innerhalb von 14 Tagen
- **Behebung und Veröffentlichung:** Ziel sind 90 Tage ab Eingang; bei aktiv
  ausgenutzten Lücken deutlich schneller
- Nennung als Melder in den Release Notes, sofern gewünscht — Details
  besprechen wir im Advisory

## Koordinierte Offenlegung (Coordinated Disclosure)

Wir bitten darum, Details einer Schwachstelle (vulnerability) erst zu
veröffentlichen, nachdem ein Fix bereitsteht oder die 90 Tage abgelaufen sind.
Die Offenlegung (disclosure) erfolgt über ein GitHub Security Advisory samt
CVE-Anforderung, sofern angebracht, und wird im
[CHANGELOG.md](https://github.com/Celestial0579/Hengstverzeichnis_Framework/blob/main/CHANGELOG.md)
vermerkt. Rechtliche Schritte gegen Melder, die sich an diese Policy halten und
ausschließlich auf eigenen Installationen testen, sind ausgeschlossen.

## Unterstützte Versionen

Da sich das Projekt aktuell in der Beta-Phase befindet, wird jeweils nur die
neueste veröffentlichte Version mit Sicherheitsupdates versorgt.

## Bereits umgesetzte Schutzmaßnahmen

Ein Überblick über das implementierte Sicherheitskonzept (2FA, Session-
Hardening, CSRF, Verschlüsselung, Rate-Limiting, Audit-Log u. a.) findet sich
in [docs/security.md](https://github.com/Celestial0579/Hengstverzeichnis_Framework/blob/main/docs/security.md).
