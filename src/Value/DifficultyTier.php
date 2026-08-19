<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum DifficultyTier: int
{
    case VeryEasy = 1;
    case Easy = 2;
    case Average = 3;
    case Challenging = 4;
    case Hard = 5;
    case VeryHard = 6;

    /**
     * Stable lowercase tokens for the public API, in tier order (index + 1 =
     * tier value). The website keys its icons and translations off
     * strtolower(name), which is not a good wire format. A list constant so
     * that API parameter declarations (attributes) can reference the enum.
     *
     * @var list<string>
     */
    public const array API_VALUES = ['very_easy', 'easy', 'average', 'challenging', 'hard', 'very_hard'];

    public function toApiValue(): string
    {
        return self::API_VALUES[$this->value - 1];
    }

    /**
     * Inverse of toApiValue(): the tier for a public API token, null for
     * anything that is not one (the API reports that as a validation error).
     */
    public static function fromApiValue(string $value): null|self
    {
        $index = array_search($value, self::API_VALUES, true);

        return $index === false ? null : self::from($index + 1);
    }

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score < 0.75 => self::VeryEasy,
            $score < 0.90 => self::Easy,
            $score < 1.10 => self::Average,
            $score < 1.25 => self::Challenging,
            $score < 1.45 => self::Hard,
            default => self::VeryHard,
        };
    }
}
