# Entwicklerdokumentation – Hengstverzeichnis Framework

Diese Dokumentation richtet sich an Entwickler, die am Code des Frameworks
weiterarbeiten. Sie beschreibt Architektur, Datenmodell, Sicherheitskonzept
und lokale Entwicklungsumgebung auf Überblicksebene (keine
Methode-für-Methode-Referenz – dafür bitte den Quellcode und die
PHPDoc-Kommentare in `src/` konsultieren).

Für **Installation/Betrieb** und **Bedienung der Anwendung** siehe das
[GitHub Wiki](../../wiki) des Projekts – dort liegt die Doku für
Admins/Betreiber und Endnutzer.

## Inhalt

- [architecture.md](architecture.md) – Aufbau der Anwendung, Request-Flow, Verzeichnisstruktur
- [database.md](database.md) – Datenmodell, Tabellen, Beziehungen, Schema-Migration
- [security.md](security.md) – Auth, 2FA, Sessions, Verschlüsselung, Rate-Limiting, Audit-Log
- [development.md](development.md) – Lokale Entwicklungsumgebung, Coding-Konventionen, Deployment
- [plugin-development.md](plugin-development.md) – Plugin-System: Manifest-Format, verfügbare Hooks, Routen, Sicherheitsgrenzen ([#56](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/56))
- [plugin-system-plan.md](plugin-system-plan.md) – Zugrundeliegende Architekturentscheidungen/Umsetzungsplanung für das Plugin-System

## Projektüberblick

Das Hengstverzeichnis Framework ist eine Open-Source-Webanwendung zur
Verwaltung und öffentlichen Darstellung von Blutlinien/Stammbäumen in der
Pferdezucht (Hengstverzeichnis, vergleichbar mit Zuchtverzeichnissen wie dem
IGF-Hengstverzeichnis). Es ist ein klassisches serverseitig gerendertes
PHP-Framework **ohne externe Abhängigkeiten** (kein Composer/npm nötig) –
bewusst schlank gehalten, um auf einfachem Shared-Hosting genauso lauffähig
zu sein wie in Docker.

**Tech-Stack:** PHP 8.3, MySQL/MariaDB (PDO), Apache, reines HTML/CSS/JS
(kein Frontend-Framework), Docker/Docker Compose für den Betrieb.
