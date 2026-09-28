<?php
// tests/Unit/Security/RateLimiterTypeTest.php

namespace Tests\Unit\Security;

use App\Security\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Zählertypen passen in die Spalte login_attempts.type (Audit M8).
 *
 * Der Typ 'force_password_change' hatte 21 Zeichen, die Spalte fasst 20. Das
 * INSERT scheiterte, der RateLimiter schluckte den Fehler als
 * "Datenbank weg", und die Sperre des erzwungenen Passwortwechsels griff nie.
 * Zwei Sicherungen dagegen:
 *
 * 1. Der RateLimiter lehnt zu lange und leere Typen LAUT ab - vor jedem
 *    Datenbankzugriff, deshalb läuft das hier ohne Datenbank.
 * 2. Ein Quelltext-Scan prüft jeden Typ, den der Kern tatsächlich übergibt.
 *    So fällt ein zu langer Typ schon im Unit-Lauf auf, nicht erst im
 *    Formular.
 */
class RateLimiterTypeTest extends TestCase {

    /** @return array<string, array{0: string}> */
    public static function unzulaessigeTypen(): array {
        return [
            '21 Zeichen' => ['force_password_change'],
            'leer' => [''],
        ];
    }

    #[DataProvider('unzulaessigeTypen')]
    public function testTooManyAttemptsLehntAb(string $typ): void {
        $this->expectException(\InvalidArgumentException::class);
        RateLimiter::tooManyAttempts('x', $typ);
    }

    #[DataProvider('unzulaessigeTypen')]
    public function testRecordAttemptLehntAb(string $typ): void {
        $this->expectException(\InvalidArgumentException::class);
        RateLimiter::recordAttempt('x', $typ);
    }

    #[DataProvider('unzulaessigeTypen')]
    public function testClearAttemptsLehntAb(string $typ): void {
        $this->expectException(\InvalidArgumentException::class);
        RateLimiter::clearAttempts('x', $typ);
    }

    #[DataProvider('unzulaessigeTypen')]
    public function testReserveAttemptLehntAb(string $typ): void {
        $this->expectException(\InvalidArgumentException::class);
        RateLimiter::reserveAttempt('x', $typ);
    }

    public function testGrenzeEntsprichtDerSpalte(): void {
        $schema = (string)file_get_contents(__DIR__ . '/../../../database/schema.sql');
        $this->assertMatchesRegularExpression(
            '/CREATE TABLE[^;]*`?login_attempts`?[^;]*`?type`? VARCHAR\(' . RateLimiter::MAX_TYPE_LENGTH . '\)/is',
            $schema,
            'MAX_TYPE_LENGTH muss zur Spalte login_attempts.type passen.'
        );
    }

    /**
     * Jeder Typ, den der Kern an den RateLimiter übergibt, ist höchstens
     * MAX_TYPE_LENGTH lang. Geprüft wird das zweite Argument jedes Aufrufs:
     * ein Literal direkt, eine Klassenkonstante über ihren Wert. Was sich so
     * nicht bestimmen lässt, fällt auf - ein Typ aus einer Variablen wäre
     * hier nicht prüfbar und gehört dann in eine Konstante.
     */
    public function testAlleTypenImKernPassenInDieSpalte(): void {
        $src = realpath(__DIR__ . '/../../../src');
        $this->assertNotFalse($src);

        $gefunden = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));
        foreach ($iterator as $datei) {
            if (!$datei->isFile() || $datei->getExtension() !== 'php') {
                continue;
            }
            foreach ($this->typenIn((string)$datei->getPathname()) as [$zeile, $typ]) {
                $ort = substr((string)$datei->getPathname(), strlen($src) + 1) . ':' . $zeile;
                $gefunden[$ort] = $typ;
                $this->assertNotSame('', $typ, "{$ort}: leerer Typ");
                $this->assertLessThanOrEqual(
                    RateLimiter::MAX_TYPE_LENGTH,
                    strlen($typ),
                    "{$ort}: Typ '{$typ}' ist länger als login_attempts.type (Audit M8)."
                );
            }
        }

        // Plausibilität: Der Scan muss die bekannten Stellen auch sehen.
        $this->assertContains('login_ip', $gefunden);
        $this->assertContains('force_pw_change', $gefunden);
        $this->assertContains('login_konto', $gefunden);
        $this->assertContains('password_reset_to', $gefunden);
        $this->assertContains('2fa_email_send', $gefunden);
        $this->assertContains('verify_resend', $gefunden);
    }

    /**
     * @return list<array{0: int, 1: string}> Zeile und aufgelöster Typ
     */
    private function typenIn(string $pfad): array {
        $tokens = token_get_all((string)file_get_contents($pfad));
        $namespace = '';
        $klasse = null;
        $ergebnis = [];
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && $t[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $n && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                        $namespace .= $tokens[$j][1];
                    }
                }
                continue;
            }
            if (is_array($t) && $t[0] === T_CLASS && $klasse === null) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $klasse = $namespace . '\\' . $tokens[$j][1];
                        break;
                    }
                }
                continue;
            }

            // ...RateLimiter :: methode (
            if (!is_array($t) || !in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                continue;
            }
            if (!preg_match('/(^|\\\\)RateLimiter$/', $t[1])) {
                continue;
            }
            $j = $this->naechstes($tokens, $i + 1);
            if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_DOUBLE_COLON) {
                continue;
            }
            $j = $this->naechstes($tokens, $j + 1);
            if ($j === null || !is_array($tokens[$j])
                || !in_array($tokens[$j][1], ['tooManyAttempts', 'recordAttempt', 'clearAttempts', 'reserveAttempt'], true)) {
                continue;
            }
            $zeile = $tokens[$j][2];
            $j = $this->naechstes($tokens, $j + 1);
            if ($j === null || $tokens[$j] !== '(') {
                continue;
            }

            // Zweites Argument einsammeln.
            $tiefe = 0;
            $argument = 0;
            $teile = [];
            for ($k = $j + 1; $k < $n; $k++) {
                $tk = $tokens[$k];
                if (in_array($tk, ['(', '[', '{'], true)) {
                    $tiefe++;
                } elseif (in_array($tk, [')', ']', '}'], true)) {
                    if ($tiefe === 0) {
                        break;
                    }
                    $tiefe--;
                } elseif ($tk === ',' && $tiefe === 0) {
                    $argument++;
                    continue;
                }
                if ($argument === 1 && !(is_array($tk) && in_array($tk[0], [T_WHITESPACE, T_COMMENT], true))) {
                    $teile[] = $tk;
                }
            }

            $ergebnis[] = [$zeile, $this->aufloesen($teile, $klasse, $namespace, $pfad, $zeile)];
        }

        return $ergebnis;
    }

    /** @param array<int, mixed> $tokens */
    private function naechstes(array $tokens, int $ab): ?int {
        for ($i = $ab, $n = count($tokens); $i < $n; $i++) {
            if (!(is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT], true))) {
                return $i;
            }
        }
        return null;
    }

    /** @param array<int, mixed> $teile */
    private function aufloesen(array $teile, ?string $klasse, string $namespace, string $pfad, int $zeile): string {
        if (count($teile) === 1 && is_array($teile[0]) && $teile[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            // Die Typen sind schlichte Wörter ohne Escapes - die Anführungs-
            // zeichen abzuschneiden genügt.
            return substr($teile[0][1], 1, -1);
        }
        if (count($teile) === 3 && is_array($teile[0]) && is_array($teile[1]) && $teile[1][0] === T_DOUBLE_COLON && is_array($teile[2])) {
            $name = ltrim($teile[0][1], '\\');
            $kandidaten = in_array($name, ['self', 'static'], true)
                ? [(string)$klasse]
                : [$namespace . '\\' . $name, 'App\\Security\\' . $name, 'App\\Service\\' . $name, 'App\\Controllers\\' . $name, $name];
            foreach ($kandidaten as $fqcn) {
                if (class_exists($fqcn)) {
                    $konstanten = (new \ReflectionClass($fqcn))->getConstants();
                    if (array_key_exists($teile[2][1], $konstanten)) {
                        return (string)$konstanten[$teile[2][1]];
                    }
                }
            }
        }
        $this->fail("{$pfad}:{$zeile}: RateLimiter-Typ ist weder Literal noch Klassenkonstante und damit nicht prüfbar.");
    }
}
