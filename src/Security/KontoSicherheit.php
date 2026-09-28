<?php
// src/Security/KontoSicherheit.php

namespace App\Security;

use App\Database;
use App\Service\AuditLogger;
use App\Service\Mailer;

/**
 * Nebenwirkungen sicherheitsrelevanter Kontoänderungen - an EINER Stelle
 * (Audit M16, N60).
 *
 * WARUM. Jeder der vier Passwortwege (Profil, Reset per Link, erzwungener
 * Wechsel, Neusetzung durch die Verwaltung) pflegte seine eigene Liste von
 * Dingen, die ein Passwortwechsel beenden muss. Beim Mailcode (#354) wurde
 * sie an vier Orten nachgetragen - beim offenen Adressantrag an keinem. Ein
 * Angreifer, der einen Antrag vorbereitet hatte, konnte ihn deshalb nach der
 * Incident-Response noch bestätigen, sich die Adresse umtragen und das Konto
 * über „Passwort vergessen" zurückholen. Wer künftig etwas ergänzt, ergänzt
 * es hier.
 */
final class KontoSicherheit {

    /**
     * SQL-Bruchstück für die Passwort-UPDATEs: Der offene Adressantrag endet
     * im SELBEN Statement wie das alte Passwort. Zwischen zwei Statements
     * bliebe sonst ein Fenster, in dem der Bestätigungslink noch greift.
     */
    public const ADRESSANTRAG_LEEREN =
        'pending_email = NULL, pending_email_token = NULL, pending_email_expires_at = NULL';

    private function __construct() {}

    /**
     * Die neue Adresse eines offenen Antrags - vor dem Passwort-UPDATE
     * abzufragen, damit nachPasswortwechsel() das Verwerfen protokollieren
     * kann, obwohl das UPDATE selbst ihn schon geleert hat.
     */
    public static function offenerAdressantrag(int $userId): ?string {
        $stmt = Database::getInstance()->prepare(
            "SELECT pending_email FROM users WHERE id = ? AND pending_email_token IS NOT NULL"
        );
        $stmt->execute([$userId]);
        $adresse = $stmt->fetchColumn();
        return $adresse === false || $adresse === null ? null : (string)$adresse;
    }

    /**
     * Alles, was ein Passwortwechsel außer dem neuen Hash beendet:
     *
     * - offene Mailcodes (#354) - ein Code, der schon in einem fremden
     *   Postfach liegt, darf den Wechsel nicht überleben
     * - einen offenen Adressantrag (Audit M16)
     * - alle API-Schlüssel (#217)
     *
     * Sitzungen beendet das UPDATE des Aufrufers (`session_version + 1`).
     * $verworfenerAntrag ist das Ergebnis von offenerAdressantrag() vor dem
     * UPDATE. Liefert die Zahl der widerrufenen Schlüssel; deren Protokoll
     * bleibt beim Aufrufer, er kennt den Anlass.
     */
    public static function nachPasswortwechsel(int $userId, ?string $verworfenerAntrag = null): int {
        EmailSecondFactor::discard($userId);

        // Doppelter Boden: Das UPDATE des Aufrufers hat den Antrag schon
        // geleert. Hat er das Bruchstück vergessen, greift es hier.
        $nachgeraeumt = self::adressantragVerwerfen($userId, 'Passwortwechsel');
        if ($verworfenerAntrag !== null && !$nachgeraeumt) {
            AuditLogger::log(
                'Offener Adressantrag verworfen',
                'security',
                sprintf('Benutzer-ID %d, Antrag auf %s (Anlass: Passwortwechsel)', $userId, $verworfenerAntrag)
            );
        }

        return ApiKey::revokeAllForUser($userId);
    }

    /**
     * Verwirft einen offenen Antrag auf eine neue Adresse. Der
     * Bestätigungslink ist danach wertlos.
     *
     * Liefert true nur, wenn wirklich einer offen war - nur dann gibt es
     * etwas zu protokollieren.
     */
    public static function adressantragVerwerfen(int $userId, string $anlass = 'Verwaltung'): bool {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT pending_email FROM users WHERE id = ? AND pending_email_token IS NOT NULL");
        $stmt->execute([$userId]);
        $adresse = $stmt->fetchColumn();

        $stmt = $db->prepare(
            "UPDATE users SET " . self::ADRESSANTRAG_LEEREN . "
             WHERE id = ? AND pending_email_token IS NOT NULL"
        );
        $stmt->execute([$userId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }

        AuditLogger::log(
            'Offener Adressantrag verworfen',
            'security',
            sprintf('Benutzer-ID %d, Antrag auf %s (Anlass: %s)', $userId, (string)$adresse, $anlass)
        );
        return true;
    }

    /**
     * Setzt ALLE zweiten Faktoren eines Kontos zurück - der „2FA Reset" der
     * Verwaltung (Audit N60).
     *
     * Bis hierher blieben die Passkeys stehen. Der Benutzer, der sein Gerät
     * verloren hatte, stand danach vor der Passkey-Seite ohne Backup-Codes -
     * und ein gestohlener Passkey blieb gültig. Ebenso verworfen werden
     * offene Mailcodes und ein offener Adressantrag.
     *
     * $sitzungenBeenden (Standard): `session_version + 1` und Widerruf aller
     * API-Schlüssel. Nach dem Reset ist das Konto faktorlos, und die
     * Einrichtung eines ersten Faktors verlangt keinen Nachweis. Eine noch
     * lebende Sitzung - genau die auf dem gestohlenen Gerät - bände sonst
     * sofort einen eigenen Faktor, und der Reset wäre wirkungslos.
     *
     * In einer Transaktion, sofern der Aufrufer keine offen hat: Ein Reset,
     * der die App löscht und die Passkeys stehen lässt, ist schlimmer als
     * keiner, weil er Sicherheit meldet, die er nicht hergestellt hat.
     *
     * @return array{passkeys: int, api_schluessel: int}
     */
    public static function zweiteFaktorenZuruecksetzen(int $userId, bool $sitzungenBeenden = true): array {
        $db = Database::getInstance();
        $eigeneTransaktion = !$db->inTransaction();
        if ($eigeneTransaktion) {
            $db->beginTransaction();
        }

        try {
            $stmt = $db->prepare(
                "UPDATE users
                 SET totp_secret = NULL, totp_enabled = 0, email_2fa_enabled = 0,
                     backup_codes = NULL, last_totp_timeslice = NULL, "
                . self::ADRESSANTRAG_LEEREN
                . ($sitzungenBeenden ? ', session_version = session_version + 1' : '')
                . " WHERE id = ?"
            );
            $stmt->execute([$userId]);

            $stmt = $db->prepare("DELETE FROM user_passkeys WHERE user_id = ?");
            $stmt->execute([$userId]);
            $passkeys = $stmt->rowCount();

            // Nicht über EmailSecondFactor::discard(): Das schluckt Fehler,
            // und ein stiller Fehlschlag hier gehört in den Rollback.
            $db->prepare("DELETE FROM email_2fa_codes WHERE user_id = ?")->execute([$userId]);

            $apiSchluessel = 0;
            if ($sitzungenBeenden) {
                $stmt = $db->prepare("UPDATE api_keys SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
                $stmt->execute([$userId]);
                $apiSchluessel = $stmt->rowCount();
            }

            if ($eigeneTransaktion) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($eigeneTransaktion && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return ['passkeys' => $passkeys, 'api_schluessel' => $apiSchluessel];
    }

    /**
     * Hinweis-Mail an den Kontoinhaber nach einer sicherheitsrelevanten
     * Änderung (Passkey hinzugefügt, Mailcode ausgeschaltet, Adresse
     * geändert, 2FA zurückgesetzt).
     *
     * Sie ist die einzige Stelle, an der der rechtmäßige Eigentümer von einer
     * Übernahme erfährt, solange sie noch umkehrbar ist. Sie bricht die
     * Aktion aber NIE ab: Ein Konto ohne Adresse oder ein ausgefallener
     * Mailserver darf die Änderung nicht rückgängig machen oder in einem
     * Fehlerbild enden. Fehlschläge landen im Audit-Log.
     *
     * $adresse überschreibt die hinterlegte Adresse - für den Hinweis an die
     * ALTE Adresse nach einem Wechsel.
     */
    public static function hinweisSenden(int $userId, string $titel, string $text, ?string $adresse = null): void {
        try {
            if ($adresse === null) {
                $stmt = Database::getInstance()->prepare("SELECT email FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $adresse = (string)($stmt->fetchColumn() ?: '');
            }
            $adresse = trim($adresse);
            if ($adresse === '') {
                return;
            }

            // Mit Empfänger: Geht der Hinweis an die ALTE Adresse (nach einem
            // Wechsel), muss im Protokoll stehen, welche das war.
            if (!(new Mailer())->sendKontoHinweis($adresse, $titel, $text)) {
                AuditLogger::log('Kontohinweis nicht zugestellt', 'security', "Benutzer-ID {$userId} an {$adresse}: {$titel}");
            }
        } catch (\Throwable $e) {
            try {
                AuditLogger::log(
                    'Kontohinweis nicht zugestellt',
                    'security',
                    "Benutzer-ID {$userId}: {$titel} ({$e->getMessage()})"
                );
            } catch (\Throwable $ignoriert) {
                // Auch das Protokoll darf die Aktion nicht abbrechen.
            }
        }
    }
}
