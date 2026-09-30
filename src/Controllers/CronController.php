<?php
// src/Controllers/CronController.php

namespace App\Controllers;

use App\Service\Scheduler;

/**
 * Class CronController
 *
 * Öffentlicher, aber durch ein Secret geschützter Auslöse-Endpunkt für die
 * Cron-/Scheduler-Infrastruktur (#67, siehe App\Service\Scheduler). Ein
 * System-Cron (z. B. `crontab -e`) ruft diesen Endpunkt periodisch auf, ohne
 * dass dafür eine eingeloggte Admin-Session nötig wäre - siehe /admin/cron
 * für die manuelle Alternative und die Secret-Verwaltung.
 *
 * Bewusst KEIN checkAuth()/requireAdmin(): das gemeinsame Secret ist hier
 * der einzige, aber ausreichende Schutzmechanismus (analog zu Webhook-
 * Endpunkten Dritter) - ein System-Cron kann keine Login-Session mitbringen.
 */
class CronController extends BaseController {

    public function run(): void {
        header('Content-Type: application/json; charset=utf-8');

        $configuredSecret = trim((string)($this->settings['cron_secret'] ?? ''));
        if ($configuredSecret === '') {
            http_response_code(503);
            echo json_encode([
                'error' => 'Cron ist nicht konfiguriert. Bitte zunächst unter /admin/cron ein Secret hinterlegen.',
            ]);
            return;
        }

        // Nur der Header wird akzeptiert - ein Secret im Query-String (?token=)
        // würde in Webserver-/Proxy-Access-Logs und ggf. Referer-Headern landen
        // (Issue #114).
        $providedSecret = $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
        if (!is_string($providedSecret) || $providedSecret === '' || !hash_equals($configuredSecret, $providedSecret)) {
            http_response_code(403);
            echo json_encode(['error' => 'Ungültiges oder fehlendes Cron-Secret.']);
            return;
        }

        // 'ran' enthält wie bisher nur ausgeführte Aufgaben (ok/error).
        // Übersprungene - fällig, aber gerade in einem anderen Cron-Aufruf
        // unterwegs (Audit M38) - stehen als Namensliste unter 'skipped'.
        $ran = [];
        $skipped = [];
        foreach (Scheduler::runDue() as $result) {
            if ($result['status'] === 'skipped') {
                $skipped[] = $result['name'];
            } else {
                $ran[] = $result;
            }
        }
        echo json_encode(['ran' => $ran, 'skipped' => $skipped, 'timestamp' => date('c')]);
    }
}
