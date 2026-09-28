<?php
// src/Service/InstallEpoch.php

namespace App\Service;

/**
 * Installationsepoche (Audit M24): eine zufällige Kennung in
 * `settings.install_epoch`, die jede Einrichtung und jeder Werksreset neu
 * würfelt.
 *
 * WOZU. Eine PHP-Sitzung nennt ihr Konto nur über eine Zahl
 * (`user_id`, `pending_2fa_user_id`, `passkey_bestanden`). Nach einem
 * Werksreset oder einem Setup mit neuer bzw. überschriebener Datenbank
 * vergibt die Datenbank dieselben Zahlen wieder - eine noch offene Sitzung
 * eines früheren Benutzers galt dann für das neu angelegte Konto mit
 * derselben ID, beim Setup-Admin bis hin zu vollen Administratorrechten.
 *
 * Jede Anmeldung (LoginSession::establish()) und jede halbe Anmeldung
 * (LoginSession::beginSecondFactor()) merkt sich die Epoche. Der
 * BaseController-Konstruktor und LoginSession::validate() verwerfen jede
 * Identität, deren Epoche nicht mehr passt. Eine Sitzung OHNE gemerkte
 * Epoche gilt als veraltet - dieselbe Linie wie bei session_version (#113).
 *
 * Der Wert ist kein Geheimnis. Er unterscheidet nur Installationen und
 * landet deshalb unbedenklich in $settings und in Backups. Manuell von einer
 * Instanz in eine andere kopieren sollte man ihn nicht: Dann gälten die
 * Sitzungen der einen Instanz auch in der anderen, sofern dort dieselben IDs
 * vergeben sind.
 */
final class InstallEpoch {

    public const SETTING = 'install_epoch';

    private function __construct() {}

    /**
     * Würfelt eine neue Epoche und beendet damit jede bestehende Anmeldung
     * dieser Installation - auch halbe während der 2FA.
     */
    public static function renew(\PDO $db): string {
        $epoche = bin2hex(random_bytes(16));
        $db->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([self::SETTING, $epoche]);
        return $epoche;
    }

    /**
     * Aktuelle Epoche: '' bei fehlender Zeile, null bei einem Datenbankfehler.
     */
    public static function current(\PDO $db): ?string {
        try {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([self::SETTING]);
            $wert = $stmt->fetchColumn();
            return $wert === false || $wert === null ? '' : (string)$wert;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Passt die in der Sitzung gemerkte Epoche zum Datenbankwert?
     *
     * Fehlt der Schlüssel in der Sitzung oder ist er kein String, ist die
     * Sitzung veraltet (Login vor diesem Mechanismus).
     */
    public static function matches(string $dbWert): bool {
        $inSitzung = $_SESSION[self::SETTING] ?? null;
        return is_string($inSitzung) && hash_equals($dbWert, $inSitzung);
    }
}
