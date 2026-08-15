<?php
// docs/examples/demo-plugin/lang/de.php
// Demonstriert die Plugin-i18n-Konvention (#48): wird beim Aktivieren
// automatisch unter der Domain "demo-plugin" registriert, siehe
// App\Plugin\PluginManager::loadPlugin() und App\I18n\Translator.

return [
    'detail_heading' => '👋 Demo-Plugin',
    'detail_text' => 'Dieser Abschnitt wurde vom Demo-Plugin über den Hook horse.detail_sections ergänzt, ohne eine einzige Kern-Datei zu verändern.',
    'detail_station' => 'Öffentlich sichtbare Deckstation laut Hook-Vertrag: {station} ({email}).',
    'edit_heading' => '👋 Demo-Plugin: Abschnitt in der Hengstverwaltung',
    'edit_text' => 'Dieser Abschnitt kommt über den Hook horse.edit_sections und kennt das Pferd bereits aus dem Aufrufkontext: #{id} ({name}). Ein echtes Addon hätte hier sein eigenes Formular mit eigener POST-Route - der Speichern-Knopf oben speichert diesen Abschnitt nicht mit.',
];
