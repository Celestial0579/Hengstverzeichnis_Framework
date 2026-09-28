<?php
// src/Service/MitgliedsstatusAltbestand.php

namespace App\Service;

use PDO;

/**
 * Mitgliedsstatus-Werte aus v0.7, die noch kein Addon übernommen hat
 * (Audit N78, Framework#395, Addon `mitgliedsstatus` ab 1.1.0).
 *
 * DER FALL. Eine v0.7-Instanz springt über den Updater direkt auf einen Kern
 * nach #395. Ihr `contacts` hatte die Spalte `membership_status` nie - die
 * Werte stehen nur im stillgelegten Altbestand `persons_pre_contacts`, unter
 * denselben IDs (Schritt 336_contacts_uebernahme kopiert Personen ID-treu).
 * Das Addon `mitgliedsstatus` konnte vor dem Update nicht installiert werden,
 * weil ein 0.7-Kern `contacts` nicht kennt. Ab 1.1.0 übernimmt es die Werte
 * von dort; bis dahin weist das Dashboard Admins darauf hin.
 *
 * DIE REGEL IST DIE DES ADDONS - bewusst doppelt, weil der Kern das Addon
 * nicht voraussetzen darf. Siehe Uebernahme::altbestandQuelle() und
 * Uebernahme::einmalig() in Hengstverzeichnis_Addons,
 * plugins/mitgliedsstatus/Plugin.php. Wer eine Seite ändert, ändert beide:
 *
 *  - `persons_pre_contacts.membership_status` existiert,
 *    `contacts.membership_status` nicht,
 *  - KEIN Marker `migration_395_membership_status_faellt`. Den setzt der
 *    Schritt 395 nur, wenn er die Spalte in `contacts` tatsächlich gelöscht
 *    hat; dann lief die Instanz über 0.8/0.9, und der Altbestand ist der
 *    womöglich überholte Stand der #336-Umstellung - kein Hinweis.
 *  - gezählt wird per JOIN auf `contacts` (gelöschte und zusammengeführte
 *    Kontakte fallen heraus), ohne vom Kern anonymisierte Kontakte (Name exakt
 *    wie KontaktDsgvo::anonymName()) und ohne Werte, die nur aus
 *    Leerraum bestehen (dieselbe REGEXP_REPLACE-Regel wie im Addon, Audit N32).
 *  - 0, sobald das Addon übernommen hat: Der Addon-Marker
 *    `plugin_mitgliedsstatus_uebernahme` (JSON) existiert UND trägt ein Feld
 *    `quelle` ODER einen `grund` ungleich 'keine-spalte'. Ein Marker
 *    'keine-spalte' ohne `quelle` stammt von 1.0.0, die den Altbestand nicht
 *    kannte - 1.1.0 holt ihn einmal nach, der Hinweis bleibt deshalb stehen.
 *
 * Dieses Markerformat ist ein Vertrag zwischen den Repos (README des Addons).
 *
 * WOFÜR NOCH. Ein künftiges Entfernen oder Pseudonymisieren der
 * *_pre_contacts-Tabellen muss offen() === 0 abwarten bzw.
 * `persons_pre_contacts.id` und `membership_status` stehen lassen - siehe den
 * Kommentar am Schritt 336_altbestand_stilllegen im SchemaMigrator. Die
 * DSGVO-Behandlung je Kontakt (Audit M23, App\Service\KontaktDsgvo) hält das
 * ein: Sie anonymisiert nur die Altzeile eines DSGVO-Falls (id bleibt,
 * membership_status wird genullt) und löscht außerhalb eines DSGVO-Falls nur
 * Altzeilen, an denen kein Kontakt mehr hängt - die zählt offen() ohnehin
 * nicht.
 */
final class MitgliedsstatusAltbestand {

    public const ALTBESTAND = 'persons_pre_contacts';
    public const MARKER_395 = 'migration_395_membership_status_faellt';
    public const ADDON_MARKER = 'plugin_mitgliedsstatus_uebernahme';

    /** Leerraum am Rand - identisch zu Status::RANDLEERRAUM im Addon. */
    public const RANDLEERRAUM = '^[[:space:]]+|[[:space:]]+$';

    private function __construct() {}

    /** Die Randleerraum-Normierung für eine Spalte (Bezeichner aus dem Code, nie eine Eingabe). */
    public static function normiert(string $spalte): string {
        return 'REGEXP_REPLACE(' . $spalte . ", '" . self::RANDLEERRAUM . "', '')";
    }

    /**
     * Anzahl der Kontakte mit einem Wert, der nur noch im Altbestand steht
     * und noch nicht übernommen ist.
     *
     * Jeder Fehler ergibt 0: Das ist ein Hinweis, und ein Hinweis darf das
     * Dashboard nicht brechen.
     */
    public static function offen(PDO $pdo): int {
        try {
            if (!self::tabelleExistiert($pdo, self::ALTBESTAND)
                || !self::spalteExistiert($pdo, self::ALTBESTAND, 'membership_status')
                || self::spalteExistiert($pdo, 'contacts', 'membership_status')
            ) {
                return 0;
            }

            if (self::einstellung($pdo, self::MARKER_395) !== null) {
                return 0;
            }

            $marker = self::einstellung($pdo, self::ADDON_MARKER);
            if ($marker !== null) {
                $bericht = json_decode($marker, true);
                if (!is_array($bericht)
                    || array_key_exists('quelle', $bericht)
                    || ($bericht['grund'] ?? null) !== 'keine-spalte'
                ) {
                    return 0;
                }
            }

            return (int)$pdo->query(
                'SELECT COUNT(*)
                 FROM `' . self::ALTBESTAND . '` p
                 JOIN contacts c ON c.id = p.id
                 WHERE p.membership_status IS NOT NULL
                   AND ' . self::normiert('p.membership_status') . " <> ''
                   AND c.name <> CONCAT('Anonymisierte Person (#', c.id, ')')"
            )->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function tabelleExistiert(PDO $pdo, string $name): bool {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($name));
        return $stmt !== false && $stmt->rowCount() > 0;
    }

    private static function spalteExistiert(PDO $pdo, string $tabelle, string $spalte): bool {
        $stmt = $pdo->query(
            'SHOW COLUMNS FROM `' . str_replace('`', '``', $tabelle) . '` LIKE ' . $pdo->quote($spalte)
        );
        return $stmt !== false && $stmt->rowCount() > 0;
    }

    private static function einstellung(PDO $pdo, string $schluessel): ?string {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$schluessel]);
        $wert = $stmt->fetchColumn();
        return $wert === false ? null : (string)$wert;
    }
}
