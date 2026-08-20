<?php
// src/Service/HorseSearchCondition.php

namespace App\Service;

/**
 * Das vollständige Vokabular der Pferdesuche: Jede Bedingung, die die Suche
 * an eine Abfrage anhängen kann, hat hier genau einen Fall - und keinen
 * anderen kann es geben.
 *
 * WOZU ein eigener Aufzählungstyp, wo vorher einfach die SQL-Zeichenkette
 * herumgereicht wurde: Er ist die Grenze zwischen dem Teil, der die Anfrage
 * liest (HorseSearchCriteria), und dem Teil, der SQL erzeugt
 * (HorseSearchSql). Über diese Grenze geht ausschließlich ein Fall dieses
 * Typs - keine Zeichenkette, kein Spaltenname, kein Wert aus $_GET.
 *
 * Der Anlass war ein Fund von Semgrep
 * (php.lang.security.injection.tainted-sql-string) an den vier Stellen, an
 * denen HorseController::index() und PublicController::catalog() die
 * Fragmente in ihre Abfragen einsetzen. Sachlich war er ein Fehlalarm - die
 * Fragmente bestanden schon damals nur aus Literalen. Er zeigte aber auf
 * eine echte Schwäche der Bauform: EINE Klasse las die Anfrage UND erzeugte
 * SQL. Solange beides beieinanderliegt, ist der nächste Zusatz der Art "hier
 * reicht doch schnell ein Spaltenname aus dem Request" nur eine Zeile weit
 * entfernt, und niemandem fiele es auf. Seit der Trennung scheitert dieser
 * Zusatz schon an der Signatur von HorseSearchSql::add(): Sie nimmt nichts
 * entgegen als diesen Typ.
 *
 * placeholders() nennt, wie viele gebundene Werte der zugehörige
 * SQL-Ausschnitt erwartet. Die Zahl steht bewusst HIER und nicht auf einer
 * der beiden Seiten, denn beide brauchen sie und beide könnten sie
 * auseinanderlaufen lassen: HorseSearchCriteria prüft damit beim Anmelden
 * die übergebenen Werte, HorseSearchSqlSafetyTest prüft damit den erzeugten
 * Ausschnitt. Passen Platzhalter und Parameter nicht zusammen, bindet PDO an
 * die falschen Stellen und die Suche liefert stillschweigend Unsinn - der
 * Fehler, der beim Auseinandernehmen einer solchen Klasse am ehesten
 * passiert.
 */
enum HorseSearchCondition {

    /** Gelöschte bleiben in beiden Kontexten außen vor; dafür gibt es den Papierkorb. */
    case NotDeleted;

    /** Öffentliche Sichtbarkeit: nur veröffentlichte Pferde (is_published). */
    case PublishedOnly;

    /** Verwaltung: der optionale Veröffentlichungs-Filter (?published=1|0), gebunden. */
    case PublishedFlag;

    /** Allgemeiner Suchbegriff über Name, Lebensnummern, Eltern, Station und Personen. */
    case FullText;

    case Name;
    case Ueln;
    case BirthYearFrom;
    case BirthYearTo;
    case Color;
    case Sex;
    case Breed;

    /** Kompatibilitäts-Mapping des früheren Status 'deceased' auf is_deceased (#188). */
    case Deceased;

    case Status;
    case Breeder;
    case Owner;
    case Station;
    case Sire;
    case Dam;

    /** Halter-Rolle (#346) - das Gegenstueck zu Breeder und Owner, das fehlte. */
    case Keeper;

    /** Stockmass von/bis in cm (#346). */
    case HeightFrom;
    case HeightTo;

    /** Todesjahr von/bis (#346). Nur gestorbene Pferde haben eines. */
    case DeathYearFrom;
    case DeathYearTo;

    /** Geburtsdatum von/bis (#346) - genauer als das Geburtsjahr. */
    case BirthDateFrom;
    case BirthDateTo;

    /** Freitext in der Beschreibung (#346). */
    case Description;

    /**
     * Einschraenkung auf eine Pferdeliste, die ein Addon geliefert hat (#346).
     *
     * WARUM ALS ID-LISTE UND NICHT ALS SQL-AUSSCHNITT. Weil die ganze Bauart
     * dieser Klassen darauf beruht, dass KEIN Anfragewert je in einen
     * SQL-String gerat - der Bauplan entsteht unabhaengig von der Anfrage,
     * Werte kommen ausschliesslich gebunden dazu (siehe
     * HorseSearchCriteria::applyTo(), Semgrep tainted-sql-string). Ein Addon,
     * das einen SQL-Ausschnitt beisteuern darf, macht genau diese Zusicherung
     * zunichte, und zwar dauerhaft: Ab dann muesste man jedem Addon glauben.
     *
     * Eine ID-Liste kann das nicht. Sie geht als EIN gebundener Wert hinein
     * (FIND_IN_SET), das Addon bekommt also volle Freiheit bei der Auswahl und
     * keine beim SQL.
     */
    case PluginIds;

    /**
     * Wie viele gebundene Werte der SQL-Ausschnitt dieser Bedingung erwartet.
     *
     * Die Zahl hängt NICHT davon ab, ob öffentlich oder in der Verwaltung
     * gesucht wird: Die beiden Fassungen von HorseSearchSql::stationMatchSql()
     * binden beide genau zwei Werte, und die Sichtbarkeitszusätze für Personen
     * kommen ganz ohne Platzhalter aus. Genau deshalb darf die Zahl hier
     * stehen, ohne den Kontext zu kennen.
     */
    public function placeholders(): int {
        return match ($this) {
            self::NotDeleted, self::PublishedOnly, self::Deceased => 0,
            // 13 Spalten des Pferds und seiner Eltern, dazu zwei für die
            // Deckstation, einer für die verknüpften Personen und einer für
            // die weiteren Lebensnummern.
            self::FullText => 17,
            // Acht ueln-Spalten plus die weiteren Lebensnummern (#246).
            self::Ueln => 9,
            self::Station, self::Sire, self::Dam => 2,
            default => 1,
        };
    }
}
