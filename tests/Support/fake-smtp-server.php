<?php
// tests/Support/fake-smtp-server.php
//
// Minimaler SMTP-Server für Tests, gestartet von Tests\Support\FakeSmtpServer.
// Er spricht genau so viel Protokoll, wie App\Service\Mailer::sendViaSmtp()
// erwartet: Begrüßung, EHLO, STARTTLS, AUTH LOGIN, MAIL FROM, RCPT TO, DATA.
//
// STARTTLS ist keine Kür, sondern Voraussetzung: Der Mailer verweigert
// unverschlüsselten Versand grundsätzlich (Sicherheitsregel in sendViaSmtp())
// und prüft das Zertifikat mit verify_peer OHNE allow_self_signed. Ein Test,
// der diesen Weg wirklich geht, braucht daher eine echte TLS-Strecke mit
// einem Zertifikat, dem der aufrufende Prozess traut - darum kümmert sich
// FakeSmtpServer (eigene CA, SSL_CERT_FILE).
//
// Jede vollständig empfangene Nachricht landet als Datei im Ablageverzeichnis,
// damit ein Test den tatsächlichen Inhalt prüfen kann statt nur den
// Rückgabewert von send().

$storageDir = getenv('FAKE_SMTP_STORAGE_DIR');
$certFile = getenv('FAKE_SMTP_CERT');
$portFile = getenv('FAKE_SMTP_PORT_FILE');

if ($storageDir === false || $certFile === false || $portFile === false) {
    fwrite(STDERR, "FAKE_SMTP_STORAGE_DIR, FAKE_SMTP_CERT und FAKE_SMTP_PORT_FILE müssen gesetzt sein.\n");
    exit(1);
}

$context = stream_context_create([
    'ssl' => [
        'local_cert' => $certFile,
        'allow_self_signed' => true,
        'verify_peer' => false,
    ],
]);

// Port 0 = das Betriebssystem sucht einen freien aus. Ein fest verdrahteter
// Port kollidiert sonst früher oder später mit einem anderen Testserver
// desselben Verzeichnisses - genau das ist beim Bau dieser Klasse passiert
// (FakeReleaseServer belegte denselben Port, der Bind schlug fehl, und die
// Bereitschaftsprüfung hielt den fremden HTTP-Server für diesen hier).
$server = @stream_socket_server(
    'tcp://127.0.0.1:0',
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);
if ($server === false) {
    fwrite(STDERR, "Konnte keinen Port belegen: {$errstr}\n");
    exit(1);
}

$address = stream_socket_get_name($server, false);
$port = (int)substr((string)$address, strrpos((string)$address, ':') + 1);
// Erst schreiben, wenn tatsächlich gelauscht wird - die Datei ist das Signal
// an den Elternprozess, dass es diesen Server wirklich gibt.
file_put_contents($portFile, (string)$port);

/** Liest eine Zeile, gibt null bei geschlossener Verbindung. */
$readLine = static function ($socket): ?string {
    $line = fgets($socket, 4096);
    return $line === false ? null : $line;
};

$received = 0;

// Eine Verbindung nach der anderen - der Mailer baut je Empfänger eine eigene
// auf, und die Tests laufen ohnehin seriell.
while (true) {
    $conn = @stream_socket_accept($server, -1);
    if ($conn === false) {
        continue;
    }

    stream_set_timeout($conn, 10);
    fwrite($conn, "220 fake-smtp bereit\r\n");

    $from = '';
    $rcpt = [];

    while (($line = $readLine($conn)) !== null) {
        $trimmed = trim($line);
        $command = strtoupper((string)strtok($trimmed, ' '));

        if ($command === 'EHLO' || $command === 'HELO') {
            // STARTTLS nur anbieten, solange noch nicht verschlüsselt wird -
            // sonst böte das zweite EHLO nach dem Handshake es erneut an.
            fwrite($conn, "250-fake-smtp\r\n250-STARTTLS\r\n250 AUTH LOGIN\r\n");
            continue;
        }

        if ($command === 'STARTTLS') {
            fwrite($conn, "220 Bereit für TLS\r\n");
            $ok = @stream_socket_enable_crypto(
                $conn,
                true,
                STREAM_CRYPTO_METHOD_TLS_SERVER
            );
            if ($ok !== true) {
                fclose($conn);
                continue 2;
            }
            continue;
        }

        if ($command === 'AUTH') {
            // AUTH LOGIN: zwei Runden Base64, beide werden akzeptiert - dieser
            // Server prüft keine Zugangsdaten, er belegt nur den Weg.
            fwrite($conn, "334 VXNlcm5hbWU6\r\n");
            $readLine($conn);
            fwrite($conn, "334 UGFzc3dvcmQ6\r\n");
            $readLine($conn);
            fwrite($conn, "235 Angenommen\r\n");
            continue;
        }

        if (str_starts_with($command, 'MAIL')) {
            $from = $trimmed;
            fwrite($conn, "250 OK\r\n");
            continue;
        }

        if (str_starts_with($command, 'RCPT')) {
            if (preg_match('/<([^>]*)>/', $trimmed, $m) === 1) {
                $rcpt[] = $m[1];
            }
            fwrite($conn, "250 OK\r\n");
            continue;
        }

        if ($command === 'DATA') {
            fwrite($conn, "354 Ende mit <CRLF>.<CRLF>\r\n");
            $message = '';
            while (($dataLine = $readLine($conn)) !== null) {
                if (rtrim($dataLine, "\r\n") === '.') {
                    break;
                }
                $message .= $dataLine;
            }

            // Fortlaufend nummeriert, nicht nach Uhrzeit benannt: Zwei
            // Nachrichten innerhalb derselben Sekunde ließen sich sonst nicht
            // mehr in ihre Reihenfolge bringen, und ein Test, der die zweite
            // Mail prüft, bekäme mal die eine und mal die andere.
            $name = sprintf('%s/mail-%06d.txt', rtrim($storageDir, '/'), ++$received);
            file_put_contents(
                $name,
                "X-Fake-Envelope-From: {$from}\r\n"
                . 'X-Fake-Envelope-To: ' . implode(', ', $rcpt) . "\r\n"
                . $message
            );

            $from = '';
            $rcpt = [];
            fwrite($conn, "250 Angenommen\r\n");
            continue;
        }

        if ($command === 'QUIT') {
            fwrite($conn, "221 Tschüss\r\n");
            break;
        }

        fwrite($conn, "250 OK\r\n");
    }

    fclose($conn);
}
