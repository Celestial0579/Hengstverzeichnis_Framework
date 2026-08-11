<?php
// src/Helper/CountryFlag.php

namespace App\Helper;

/**
 * Class CountryFlag
 *
 * Länderfreitext -> Flaggen-Emoji (#240). `persons.country` ist bewusst
 * Freitext; damit das Land neben Züchter/Besitzer/Halter trotzdem sichtbar
 * wird, mappt dieser Helper tolerant auf einen ISO-3166-alpha-2-Code und
 * baut daraus das Flaggen-Emoji aus zwei Regional-Indicator-Codepoints
 * (z. B. "DK" -> U+1F1E9 U+1F1F0 = 🇩🇰). Die Darstellung übernimmt der
 * Browser - reines Text-Rendering, keine Bilddateien.
 *
 * Akzeptiert werden deutsche UND englische Ländernamen (inkl. gebräuchlicher
 * Umlaut-Umschreibungen wie "Daenemark") sowie direkte alpha-2-Codes.
 * Unbekanntes oder Leeres ergibt null - fail-quiet, die Aufrufer zeigen dann
 * schlicht keine Flagge statt eines Platzhalters. Deshalb werden auch nur
 * Codes aus der eigenen Liste akzeptiert: Aus beliebigen zwei Buchstaben
 * ("XX") würde der Browser sonst sichtbaren Buchstaben-Müll rendern.
 */
final class CountryFlag {

    /**
     * Normalisierter Ländername (Kleinschreibung) -> ISO-3166-alpha-2.
     * Deutsche und englische Namen der europäischen Länder plus gängige
     * weltweite; bei Umlauten zusätzlich die ae/oe/ue-Schreibweise.
     */
    private const NAME_TO_ISO = [
        // Mitteleuropa / deutschsprachig
        'deutschland' => 'DE', 'germany' => 'DE',
        'österreich' => 'AT', 'oesterreich' => 'AT', 'austria' => 'AT',
        'schweiz' => 'CH', 'switzerland' => 'CH',
        'liechtenstein' => 'LI',
        'luxemburg' => 'LU', 'luxembourg' => 'LU', 'lëtzebuerg' => 'LU', 'letzebuerg' => 'LU',

        // Skandinavien / Nordeuropa
        'dänemark' => 'DK', 'daenemark' => 'DK', 'denmark' => 'DK', 'danmark' => 'DK',
        'norwegen' => 'NO', 'norway' => 'NO', 'norge' => 'NO',
        'schweden' => 'SE', 'sweden' => 'SE', 'sverige' => 'SE',
        'finnland' => 'FI', 'finland' => 'FI', 'suomi' => 'FI',
        'island' => 'IS', 'iceland' => 'IS',
        'grönland' => 'GL', 'groenland' => 'GL', 'greenland' => 'GL',
        'färöer' => 'FO', 'faeroeer' => 'FO', 'faroe islands' => 'FO',

        // Westeuropa
        'frankreich' => 'FR', 'france' => 'FR',
        'niederlande' => 'NL', 'netherlands' => 'NL', 'nederland' => 'NL', 'holland' => 'NL',
        'belgien' => 'BE', 'belgium' => 'BE',
        'großbritannien' => 'GB', 'grossbritannien' => 'GB', 'great britain' => 'GB',
        'vereinigtes königreich' => 'GB', 'vereinigtes koenigreich' => 'GB',
        'united kingdom' => 'GB', 'uk' => 'GB', 'england' => 'GB',
        'irland' => 'IE', 'ireland' => 'IE',
        'monaco' => 'MC',
        'andorra' => 'AD',

        // Südeuropa
        'spanien' => 'ES', 'spain' => 'ES',
        'portugal' => 'PT',
        'italien' => 'IT', 'italy' => 'IT',
        'griechenland' => 'GR', 'greece' => 'GR',
        'malta' => 'MT',
        'zypern' => 'CY', 'cyprus' => 'CY',
        'san marino' => 'SM',
        'vatikan' => 'VA', 'vatikanstadt' => 'VA', 'vatican city' => 'VA',

        // Ost-/Südosteuropa
        'polen' => 'PL', 'poland' => 'PL',
        'tschechien' => 'CZ', 'tschechische republik' => 'CZ', 'czech republic' => 'CZ', 'czechia' => 'CZ',
        'slowakei' => 'SK', 'slovakia' => 'SK',
        'ungarn' => 'HU', 'hungary' => 'HU',
        'slowenien' => 'SI', 'slovenia' => 'SI',
        'kroatien' => 'HR', 'croatia' => 'HR',
        'serbien' => 'RS', 'serbia' => 'RS',
        'bosnien und herzegowina' => 'BA', 'bosnia and herzegovina' => 'BA',
        'montenegro' => 'ME',
        'nordmazedonien' => 'MK', 'north macedonia' => 'MK',
        'albanien' => 'AL', 'albania' => 'AL',
        'rumänien' => 'RO', 'rumaenien' => 'RO', 'romania' => 'RO',
        'bulgarien' => 'BG', 'bulgaria' => 'BG',
        'estland' => 'EE', 'estonia' => 'EE',
        'lettland' => 'LV', 'latvia' => 'LV',
        'litauen' => 'LT', 'lithuania' => 'LT',
        'ukraine' => 'UA',
        'belarus' => 'BY', 'weißrussland' => 'BY', 'weissrussland' => 'BY',
        'russland' => 'RU', 'russia' => 'RU',
        'moldau' => 'MD', 'republik moldau' => 'MD', 'moldova' => 'MD',
        'türkei' => 'TR', 'tuerkei' => 'TR', 'turkey' => 'TR', 'türkiye' => 'TR', 'turkiye' => 'TR',

        // Kaukasus / Zentralasien
        'georgien' => 'GE', 'georgia' => 'GE',
        'armenien' => 'AM', 'armenia' => 'AM',
        'aserbaidschan' => 'AZ', 'azerbaijan' => 'AZ',
        'kasachstan' => 'KZ', 'kazakhstan' => 'KZ',

        // Amerika
        'usa' => 'US', 'vereinigte staaten' => 'US', 'united states' => 'US',
        'united states of america' => 'US',
        'kanada' => 'CA', 'canada' => 'CA',
        'mexiko' => 'MX', 'mexico' => 'MX',
        'brasilien' => 'BR', 'brazil' => 'BR',
        'argentinien' => 'AR', 'argentina' => 'AR',
        'chile' => 'CL',
        'uruguay' => 'UY',
        'paraguay' => 'PY',
        'kolumbien' => 'CO', 'colombia' => 'CO',
        'peru' => 'PE',
        'ecuador' => 'EC',
        'bolivien' => 'BO', 'bolivia' => 'BO',
        'venezuela' => 'VE',
        'kuba' => 'CU', 'cuba' => 'CU',
        'costa rica' => 'CR',

        // Asien / Ozeanien
        'japan' => 'JP',
        'china' => 'CN',
        'südkorea' => 'KR', 'suedkorea' => 'KR', 'south korea' => 'KR',
        'indien' => 'IN', 'india' => 'IN',
        'indonesien' => 'ID', 'indonesia' => 'ID',
        'thailand' => 'TH',
        'vietnam' => 'VN',
        'philippinen' => 'PH', 'philippines' => 'PH',
        'singapur' => 'SG', 'singapore' => 'SG',
        'malaysia' => 'MY',
        'pakistan' => 'PK',
        'israel' => 'IL',
        'iran' => 'IR',
        'saudi-arabien' => 'SA', 'saudi arabien' => 'SA', 'saudi arabia' => 'SA',
        'vereinigte arabische emirate' => 'AE', 'united arab emirates' => 'AE',
        'katar' => 'QA', 'qatar' => 'QA',
        'australien' => 'AU', 'australia' => 'AU',
        'neuseeland' => 'NZ', 'new zealand' => 'NZ',

        // Afrika
        'südafrika' => 'ZA', 'suedafrika' => 'ZA', 'south africa' => 'ZA',
        'ägypten' => 'EG', 'aegypten' => 'EG', 'egypt' => 'EG',
        'marokko' => 'MA', 'morocco' => 'MA',
        'tunesien' => 'TN', 'tunisia' => 'TN',
        'algerien' => 'DZ', 'algeria' => 'DZ',
        'kenia' => 'KE', 'kenya' => 'KE',
        'nigeria' => 'NG',
        'äthiopien' => 'ET', 'aethiopien' => 'ET', 'ethiopia' => 'ET',
    ];

    /**
     * Flaggen-Emoji für einen Länder-Freitext oder null, wenn das Land nicht
     * erkannt wird (dann zeigen die Aufrufer schlicht keine Flagge an).
     */
    public static function emoji(?string $country): ?string {
        $normalized = mb_strtolower(trim((string)$country), 'UTF-8');
        if ($normalized === '') {
            return null;
        }

        // Direkter alpha-2-Code ("DK", "no") - aber nur, wenn er in der
        // eigenen Liste vorkommt: Beliebige Buchstabenpaare ergäben kein
        // gültiges Flaggen-Emoji, sondern sichtbare Regional-Indicator-Zeichen.
        if (preg_match('/^[a-z]{2}$/', $normalized) && in_array(strtoupper($normalized), self::NAME_TO_ISO, true)) {
            return self::isoToEmoji(strtoupper($normalized));
        }

        $iso = self::NAME_TO_ISO[$normalized] ?? null;
        return $iso === null ? null : self::isoToEmoji($iso);
    }

    /**
     * "DK" -> "🇩🇰": jeder Buchstabe wird auf seinen Regional-Indicator-
     * Codepoint (U+1F1E6 = 🇦 für 'A') abgebildet; das Buchstabenpaar rendern
     * Browser als Flagge des zugehörigen ISO-Codes.
     */
    private static function isoToEmoji(string $iso): string {
        $flag = '';
        foreach (str_split($iso) as $letter) {
            $flag .= mb_chr(0x1F1E6 + (ord($letter) - ord('A')), 'UTF-8');
        }
        return $flag;
    }
}
