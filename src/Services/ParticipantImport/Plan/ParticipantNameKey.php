<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport\Plan;

use SpeedPuzzling\Web\Value\SearchText;

/**
 * Two spellings of probably the same name (design doc D17): case, whitespace, diacritics and apostrophes do not
 * matter (SearchText::fold() - the one fold of the site), dashes of every kind are one.
 * "Zoë O’Brien-Novák" and "zoe o'brien–novak" have the same key.
 */
final class ParticipantNameKey
{
    private const string DASHES = "/[\u{2010}\u{2011}\u{2012}\u{2013}\u{2014}\u{2015}\u{2212}]/u";

    public static function of(string $name): string
    {
        return SearchText::fold((string) preg_replace(self::DASHES, '-', $name));
    }
}
