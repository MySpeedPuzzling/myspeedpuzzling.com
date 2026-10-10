<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How often a player gets the activity digest e-mail (docs/features/content-digest/README.md §"Frequency and periods").
 * Every value but Off maps to the length of the period one e-mail is about.
 */
enum ContentDigestFrequency: string
{
    case Weekly = 'weekly';
    case EveryTwoWeeks = 'every_two_weeks';
    case Monthly = 'monthly';
    case Off = 'off';

    public function periodLength(): null|DigestPeriodLength
    {
        return match ($this) {
            self::Weekly => DigestPeriodLength::Week,
            self::EveryTwoWeeks => DigestPeriodLength::TwoWeeks,
            self::Monthly => DigestPeriodLength::Month,
            self::Off => null,
        };
    }

    public static function forPeriodLength(DigestPeriodLength $length): self
    {
        return match ($length) {
            DigestPeriodLength::Week => self::Weekly,
            DigestPeriodLength::TwoWeeks => self::EveryTwoWeeks,
            DigestPeriodLength::Month => self::Monthly,
        };
    }

    public function translationKey(): string
    {
        return 'activity_digest.frequency.' . $this->value;
    }

    /**
     * @return list<self>
     */
    public static function sending(): array
    {
        return [self::Weekly, self::EveryTwoWeeks, self::Monthly];
    }
}
