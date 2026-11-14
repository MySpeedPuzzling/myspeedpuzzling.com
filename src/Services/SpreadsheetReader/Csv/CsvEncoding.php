<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Csv;

/**
 * Which encoding a text file is in, told the way ParticipantFileReader tells it (its byte tables and rules, copied -
 * that reader stays as it is): a byte-order mark, then UTF-16 without one, then valid UTF-8, then Windows-1250 when
 * the bytes look Central European, otherwise Windows-1252.
 */
enum CsvEncoding: string
{
    case Utf8 = 'UTF-8';
    case Utf16LittleEndian = 'UTF-16LE';
    case Utf16BigEndian = 'UTF-16BE';
    case Windows1250 = 'Windows-1250';
    case Windows1252 = 'Windows-1252';

    /**
     * Windows-1250 bytes 0x80-0xFF as Unicode code points (mbstring does not know the code page). FFFD = not defined.
     */
    private const string WINDOWS_1250 = '20AC FFFD 201A FFFD 201E 2026 2020 2021 FFFD 2030 160 2039 15A 164 17D 179 '
        . 'FFFD 2018 2019 201C 201D 2022 2013 2014 FFFD 2122 161 203A 15B 165 17E 17A '
        . 'A0 2C7 2D8 141 A4 104 A6 A7 A8 A9 15E AB AC AD AE 17B B0 B1 2DB 142 B4 B5 B6 B7 B8 105 15F BB 13D 2DD 13E 17C '
        . '154 C1 C2 102 C4 139 106 C7 10C C9 118 CB 11A CD CE 10E 110 143 147 D3 D4 150 D6 D7 158 16E DA 170 DC DD 162 DF '
        . '155 E1 E2 103 E4 13A 107 E7 10D E9 119 EB 11B ED EE 10F 111 144 148 F3 F4 151 F6 F7 159 16F FA 171 FC FD 163 2D9';

    /**
     * Bytes that are a Central European letter in Windows-1250 (Š Ś Ť Ž Ź š ś ť ž ź Ł Ą ł ą Ľ ľ) and rare in Western
     * text (Œ œ Ÿ £ ¥ ³ ¹ ¼ ¾ or nothing in Windows-1252) - evidence only next to a letter (£15 is a price).
     */
    private const array CENTRAL_EUROPEAN_BYTES = [
        0x8A, 0x8C, 0x8D, 0x8E, 0x8F, 0x9A, 0x9C, 0x9D, 0x9E, 0x9F, 0xA3, 0xA5, 0xB3, 0xB9, 0xBC, 0xBE,
    ];

    /**
     * Bytes that are a consonant in Windows-1250 (Č č Ň ň Ř ř) and a vowel in Windows-1252 (È è Ò ò Ø ø): Czech has
     * them next to vowels (Jiří, Černý, Dvořák), Western text between consonants (Hélène, Søren, Niccolò).
     */
    private const array CONSONANT_OR_VOWEL_BYTES = [0xC8, 0xE8, 0xD2, 0xF2, 0xD8, 0xF8];

    /**
     * Ě ě in Windows-1250, Ì ì in Windows-1252: ě sits inside a word (Věra, Zbyněk), ì mostly ends one (così).
     */
    private const array E_CARON_BYTES = [0xCC, 0xEC];

    /**
     * Bytes that are a Western letter in Windows-1252 (À Ã Å Õ à ã å õ û) and a rare letter in Windows-1250. Æ æ ê Ñ ñ
     * are left out: in Windows-1250 they are the common Polish Ć ć ę Ń ń, so they prove nothing.
     */
    private const array WESTERN_BYTES = [0xC0, 0xC3, 0xC5, 0xD5, 0xE0, 0xE3, 0xE5, 0xF5, 0xFB];

    /**
     * Vowels that are the same letter in both code pages (Á É Í Ó Ú Ý Ä Ö Ü Â Ô Ë Î and lower case).
     */
    private const array SHARED_VOWEL_BYTES = [
        0xC1, 0xC9, 0xCD, 0xD3, 0xDA, 0xDD, 0xE1, 0xE9, 0xED, 0xF3, 0xFA, 0xFD,
        0xC4, 0xD6, 0xDC, 0xE4, 0xF6, 0xFC, 0xC2, 0xD4, 0xE2, 0xF4, 0xCB, 0xEB, 0xCE, 0xEE,
    ];

    /**
     * @param string $head the first bytes of the file (a few hundred are enough)
     *
     * @return null|array{self, int} the encoding a byte-order mark names, and the mark's length
     */
    public static function fromByteOrderMark(string $head): null|array
    {
        return match (true) {
            str_starts_with($head, "\xEF\xBB\xBF") => [self::Utf8, 3],
            str_starts_with($head, "\xFF\xFE") => [self::Utf16LittleEndian, 2],
            str_starts_with($head, "\xFE\xFF") => [self::Utf16BigEndian, 2],
            default => null,
        };
    }

    /**
     * UTF-16 written without a mark: ASCII text has a NUL byte next to every character.
     */
    public static function utf16WithoutMark(string $head): null|self
    {
        $sample = substr($head, 0, 400);
        $length = strlen($sample) - strlen($sample) % 2;

        if ($length < 4) {
            return null;
        }

        $evenNul = 0;
        $oddNul = 0;

        for ($i = 0; $i < $length; $i += 2) {
            $evenNul += $sample[$i] === "\0" ? 1 : 0;
            $oddNul += $sample[$i + 1] === "\0" ? 1 : 0;
        }

        $pairs = $length / 2;

        return match (true) {
            $oddNul >= $pairs * 0.4 && $evenNul === 0 => self::Utf16LittleEndian,
            $evenNul >= $pairs * 0.4 && $oddNul === 0 => self::Utf16BigEndian,
            default => null,
        };
    }

    /**
     * Windows-1250 only with Central European evidence, and more of it than of Western text: most bytes 0x80-0xFF are
     * a letter in both code pages, so a byte counts by what stands next to it (see the byte lists above).
     */
    public static function looksCentralEuropean(string $sample): bool
    {
        $length = strlen($sample);
        $central = 0;
        $western = 0;

        if (preg_match_all('/[\x80-\xFF]/', $sample, $matches, PREG_OFFSET_CAPTURE) === false) {
            return false;
        }

        foreach ($matches[0] as [$character, $offset]) {
            $byte = ord($character);
            $before = $offset > 0 ? ord($sample[$offset - 1]) : null;
            $after = $offset + 1 < $length ? ord($sample[$offset + 1]) : null;

            if (in_array($byte, self::CENTRAL_EUROPEAN_BYTES, true)) {
                if (self::isLetterByte($before) || self::isLetterByte($after)) {
                    $central++;
                }
            } elseif (in_array($byte, self::WESTERN_BYTES, true)) {
                $western++;
            } elseif (in_array($byte, self::CONSONANT_OR_VOWEL_BYTES, true)) {
                if (self::isVowelByte($before) || self::isVowelByte($after)) {
                    $central++;
                } else {
                    $western++;
                }
            } elseif (in_array($byte, self::E_CARON_BYTES, true)) {
                if (self::isLetterByte($after)) {
                    $central++;
                } else {
                    $western++;
                }
            }
        }

        return $central >= 1 && $central > $western;
    }

    /**
     * A cell's bytes as UTF-8 text. Never called for UTF-16: such a file is converted as a whole first.
     */
    public function decode(string $bytes): string
    {
        if ($bytes === '' || preg_match('/[\x80-\xFF]/', $bytes) !== 1) {
            return $bytes;
        }

        return match ($this) {
            self::Windows1250 => strtr($bytes, self::windows1250Map()),
            self::Windows1252 => self::converted($bytes),
            default => mb_scrub($bytes, 'UTF-8'),
        };
    }

    private static function converted(string $bytes): string
    {
        return mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    /**
     * @return array<string, string>
     */
    private static function windows1250Map(): array
    {
        /** @var null|array<string, string> $map */
        static $map = null;

        if ($map === null) {
            $codePoints = explode(' ', self::WINDOWS_1250);
            $map = [];

            for ($byte = 0x80; $byte <= 0xFF; $byte++) {
                $map[chr($byte)] = mb_chr((int) hexdec($codePoints[$byte - 0x80] ?? 'FFFD'), 'UTF-8');
            }
        }

        return $map;
    }

    private static function isVowelByte(null|int $byte): bool
    {
        return $byte !== null
            && (in_array($byte, [0x41, 0x45, 0x49, 0x4F, 0x55, 0x59, 0x61, 0x65, 0x69, 0x6F, 0x75, 0x79], true)
                || in_array($byte, self::SHARED_VOWEL_BYTES, true));
    }

    /**
     * A letter in both code pages (or in one of them): A-Z, a-z, 0x8A-0x9F letters, 0xC0-0xFF but × and ÷.
     */
    private static function isLetterByte(null|int $byte): bool
    {
        return $byte !== null && (
            ($byte >= 0x41 && $byte <= 0x5A)
            || ($byte >= 0x61 && $byte <= 0x7A)
            || in_array($byte, [0x8A, 0x8C, 0x8D, 0x8E, 0x8F, 0x9A, 0x9C, 0x9D, 0x9E, 0x9F], true)
            || ($byte >= 0xC0 && $byte !== 0xD7 && $byte !== 0xF7)
        );
    }
}
