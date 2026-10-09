<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Value\EditionDateRule;
use SpeedPuzzling\Web\Value\EditionDateRuleKind;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * "Add several dates" (docs/features/organizations/README.md, P15/P16): how the dates are proposed - a rule (`repeat`)
 * or picked days (`pick`) - and what every edition gets: a name from `namePattern` (`{date}` = the day written in the
 * page's language) and "Who can enter". Read from the query string (a GET preview, AddEditionsFormType); the checked
 * days of the preview are posted on their own.
 */
#[Assert\Callback('validateDates')]
final class AddEditionsFormData
{
    public const string HOW_REPEAT = 'repeat';
    public const string HOW_PICK = 'pick';
    public const string DATE_PLACEHOLDER = '{date}';
    // Room for the date in an edition name of at most 250 characters
    public const int NAME_PATTERN_MAX_LENGTH = 200;
    private const string PICKED_DATE_FORMAT = 'd.m.Y';

    public function __construct(
        // Null (left out of a hand-made URL) = repeat
        public null|string $how = self::HOW_REPEAT,
        public null|EditionDateRuleKind $rule = EditionDateRuleKind::Weekly,
        // 1-4, for EditionDateRuleKind::NthWeekday
        public null|int $nth = 1,
        // ISO-8601: 1 = Monday … 7 = Sunday
        public null|int $weekday = null,
        public null|DateTimeImmutable $starting = null,
        public null|int $count = 6,
        // Picked days, as the multi-date picker writes them ("05.10.2026; 12.10.2026"); commas, "5.10.2026",
        // "5. 10. 2026" and ISO days are read too
        public null|string $dates = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: self::NAME_PATTERN_MAX_LENGTH)]
        public null|string $namePattern = null,
        #[Assert\Length(max: 120)]
        public null|string $eligibility = null,
    ) {
    }

    /**
     * "<series name> {date}" - the series name shortened so the pattern fits NAME_PATTERN_MAX_LENGTH
     */
    public static function defaultNamePattern(string $seriesName): string
    {
        $suffix = ' ' . self::DATE_PLACEHOLDER;
        $name = mb_substr(trim($seriesName), 0, self::NAME_PATTERN_MAX_LENGTH - mb_strlen($suffix));

        return rtrim($name) . $suffix;
    }

    public function validateDates(ExecutionContextInterface $context): void
    {
        if ($this->how === self::HOW_PICK) {
            $picked = $this->pickedDates();

            if ($picked === null) {
                $context->buildViolation('add_editions.dates_invalid')->atPath('dates')->addViolation();
            } elseif ($picked === []) {
                $context->buildViolation('add_editions.dates_missing')->atPath('dates')->addViolation();
            } elseif (count($picked) > EditionDateRule::MAX_COUNT) {
                $context->buildViolation('add_editions.too_many')
                    ->setParameter('%max%', (string) EditionDateRule::MAX_COUNT)
                    ->atPath('dates')
                    ->addViolation();
            }

            return;
        }

        if ($this->rule === null) {
            $context->buildViolation('add_editions.rule_missing')->atPath('rule')->addViolation();
        }

        if ($this->weekday === null || $this->weekday < 1 || $this->weekday > 7) {
            $context->buildViolation('add_editions.weekday_missing')->atPath('weekday')->addViolation();
        }

        if ($this->rule === EditionDateRuleKind::NthWeekday && ($this->nth === null || $this->nth < 1 || $this->nth > 4)) {
            $context->buildViolation('add_editions.nth_missing')->atPath('nth')->addViolation();
        }

        if ($this->starting === null) {
            $context->buildViolation('add_editions.starting_missing')->atPath('starting')->addViolation();
        }

        if ($this->count === null || $this->count < 1 || $this->count > EditionDateRule::MAX_COUNT) {
            $context->buildViolation('add_editions.count_range')
                ->setParameter('%max%', (string) EditionDateRule::MAX_COUNT)
                ->atPath('count')
                ->addViolation();
        }
    }

    /**
     * The proposed days, in date order, each once - call it on valid data only
     *
     * @return list<DateTimeImmutable>
     */
    public function proposedDates(): array
    {
        if ($this->how === self::HOW_PICK) {
            return array_slice($this->pickedDates() ?? [], 0, EditionDateRule::MAX_COUNT);
        }

        if ($this->rule === null || $this->weekday === null || $this->starting === null || $this->count === null) {
            return [];
        }

        return new EditionDateRule(
            kind: $this->rule,
            weekday: $this->weekday,
            starting: $this->starting,
            count: $this->count,
            nth: $this->nth ?? 1,
        )->dates();
    }

    /**
     * The name of the edition of `$date`: the pattern with `{date}` replaced - a pattern without it gets the date at
     * its end, so every edition of the batch has a name (and a slug) of its own
     */
    public function nameFor(string $formattedDate): string
    {
        $pattern = trim($this->namePattern ?? '');

        if (str_contains($pattern, self::DATE_PLACEHOLDER) === false) {
            $pattern = trim($pattern . ' ' . self::DATE_PLACEHOLDER);
        }

        return trim((string) preg_replace('/\s+/u', ' ', str_replace(self::DATE_PLACEHOLDER, $formattedDate, $pattern)));
    }

    /**
     * The picked days, sorted and each once; null when something typed is no day
     *
     * @return null|list<DateTimeImmutable>
     */
    public function pickedDates(): null|array
    {
        // "5. 10. 2026" (spaces after the dots, as Czech writes a date) is one day
        $typed = preg_replace('/(\d)\.\s+(?=\d)/u', '$1.', trim($this->dates ?? '')) ?? '';
        $tokens = preg_split('/[\s,;]+/', $typed, -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        $days = [];

        foreach ($tokens as $token) {
            // A date may end with a dot ("5. 10. 2026.")
            $token = rtrim($token, '.');
            $day = null;

            foreach ([self::PICKED_DATE_FORMAT, 'Y-m-d'] as $format) {
                $parsed = DateTimeImmutable::createFromFormat('!' . $format, $token, $utc);

                if ($parsed !== false && $parsed->format($format) === $token) {
                    $day = $parsed;

                    break;
                }

                // Day and month without leading zeros ("5.10.2026")
                $parsed = DateTimeImmutable::createFromFormat('!j.n.Y', $token, $utc);

                if ($parsed !== false && $parsed->format('j.n.Y') === $token) {
                    $day = $parsed;

                    break;
                }
            }

            if ($day === null) {
                return null;
            }

            $days[$day->format('Y-m-d')] = $day;
        }

        ksort($days);

        return array_values($days);
    }
}
