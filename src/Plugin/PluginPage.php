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
 * Settings-/Locale-Laden ist bewusst eine kleine, dokumentierte Kopie von
 * BaseController::loadSettings()/initLocale() (private Instanzmethoden) -
 * beide Seiten verweisen aufeinander; wer hier etwas ändert, zieht dort
 * nach.
 */
final class PluginPage {

    public static function render(string $title, string $contentHtml): void {
        $settings = self::loadSettings();
        self::initLocale($settings);

        $content = $contentHtml;
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
     * Wie BaseController::initLocale(): ?lang=xx übernimmt in die Session,
     * sonst gilt Session-Wahl vor Admin-Standardsprache; ungültige Werte
     * bildet Translator::init() selbst sicher ab.
     *
     * @param array<string, string> $settings
     */
    private static function initLocale(array $settings): void {
        $available = Translator::getAvailableLocales();

        $requested = $_GET['lang'] ?? null;
        if (is_string($requested) && isset($available[$requested])) {
            $_SESSION['locale'] = $requested;
        }

        $locale = $_SESSION['locale'] ?? ($settings['language'] ?? 'de');
        Translator::init((string)$locale);
    }
}
