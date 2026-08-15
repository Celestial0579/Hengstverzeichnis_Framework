<?php
// src/Plugin/PluginPage.php

namespace App\Plugin;

use App\Database;
use App\I18n\Translator;

/**
 * Rendert eine Plugin-Seite im zentralen Haupt-Layout (Addons#66).
 *
 * Plugin-Routen laufen als rohe Router-Callbacks OHNE BaseController -
 * bisher musste jede Plugin-Seite deshalb ein eigenständiges HTML-Dokument
 * ausgeben: ohne Header/Navigation/Footer, ohne Theme-Umschalter und ohne
 * die admin-konfigurierten Markenfarben. Genau daraus entstand die
 * Theming-Drift der Addons (14 von 15 kopierten das Muster des
 * Demo-Plugins). Dieser Dienst holt nach, was BaseController::render()
 * für Kern-Seiten tut: Einstellungen laden, Locale initialisieren und
 * layout.php mit $title/$content/$settings einbinden.
 *
 * Verwendung im Routen-Callback eines Plugins:
 *
 *     \App\Plugin\PluginPage::render('Mein Addon', $html);
 *
 * $contentHtml wird wie bei Kern-Views unescaped in <main> eingesetzt -
 * das Plugin ist (unverändert) selbst dafür verantwortlich, dynamische
 * Werte mit htmlspecialchars() zu escapen. Der Titel wird vom Layout
 * escaped.
 *
 * Settings-Laden ist bewusst eine kleine, dokumentierte Kopie von
 * BaseController::loadSettings() (private Instanzmethode) - beide Seiten
 * verweisen aufeinander; wer hier etwas ändert, zieht dort nach. Die
 * Locale-Auswahl dagegen ist NICHT kopiert, sondern zentral in
 * Translator::resolveRequestLocale(): Die frühere Kopie kannte die
 * active_locales-Prüfung (#198) nicht und machte deaktivierte Sprachen
 * über Plugin-Seiten dauerhaft erreichbar (#220).
 */
final class PluginPage {

    public static function render(string $title, string $contentHtml, bool $embed = false): void {
        $settings = self::loadSettings();
        self::initLocale($settings);

        $content = $contentHtml;

        if ($embed) {
            // Minimal-Layout ohne Kopf-/Fussbereich (#260). Der eigentliche
            // Anwendungsfall sitzt hier und nicht im Kern: Ein Addon liefert das
            // einbettbare Schnipsel (Addons#89), der Kern nur die Voraussetzung.
            // Die Frame-Lockerung greift auch hier nur bei freigegebenen Domains.
            \App\Security\FrameGuard::allowEmbedding();
            require __DIR__ . '/../Views/layout_embed.php';
            return;
        }

        require __DIR__ . '/../Views/layout.php';
    }

    /**
     * Wie BaseController::loadSettings(): alle Key-Value-Einstellungen aus
     * der settings-Tabelle, mit denselben Fallback-Werten für den
     * Setup-Modus.
     *
     * @return array<string, string>
     */
    private static function loadSettings(): array {
        try {
            $rows = Database::getInstance()->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
            $settings = [];
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            return $settings;
        } catch (\Throwable $e) {
            return [
                'site_name' => 'Hengstverzeichnis (Setup Mode)',
                'primary_color' => '#2c3e50',
                'secondary_color' => '#18bc9c',
                'site_logo' => '',
                'logo_url' => '',
            ];
        }
    }

    /**
     * Wie BaseController::initLocale(): dieselbe zentrale Auswahlregel
     * (Translator::resolveRequestLocale, nur aktivierte Sprachen wählbar,
     * veraltete Session-Wahl wird bereinigt - #220); ungültige Werte bildet
     * Translator::init() zusätzlich selbst sicher ab.
     *
     * @param array<string, string> $settings
     */
    private static function initLocale(array $settings): void {
        Translator::init(Translator::resolveRequestLocale($settings));
    }
}
