<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class RelativeTimeFormatter
{
    public function __construct(
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    public function formatDiff(
        int|string|DateTimeInterface $from,
        null|int|string|DateTimeInterface $to = null,
        null|string $locale = null,
    ): string {
        $from = $this->formatDateTime($from);
        // No $to = until now - the clock's, like every "now" of the app
        $to = $to === null ? $this->clock->now() : $this->formatDateTime($to);

        /** @var array<string, string> $units */
        $units = [
            'y' => 'year',
            'm' => 'month',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        ];

        $diff = $to->diff($from);

        foreach ($units as $attribute => $unit) {
            $count = $diff->$attribute;

            if (0 !== $count) {
                $id = sprintf('diff.%s.%s', $diff->invert ? 'ago' : 'in', $unit);

                return $this->translator->trans($id, ['%count%' => $count], 'time', $locale);
            }
        }

        return $this->translator->trans('diff.empty', [], 'time', $locale);
    }

    /**
     * The untouched catalogue entries formatDiff() picks from, for assets/relative_time.js, which words
     * the same labels in the browser while they count up (docs/features/live-activity-feed.md).
     *
     * @return array{second: string, minute: string, hour: string, day: string, empty: string}
     */
    public function browserMessages(null|string $locale = null): array
    {
        if (!$this->translator instanceof TranslatorBagInterface) {
            throw new LogicException('The translator does not expose its catalogues.');
        }

        $catalogue = $this->translator->getCatalogue($locale);

        return [
            'second' => $catalogue->get('diff.ago.second', 'time'),
            'minute' => $catalogue->get('diff.ago.minute', 'time'),
            'hour' => $catalogue->get('diff.ago.hour', 'time'),
            'day' => $catalogue->get('diff.ago.day', 'time'),
            'empty' => $catalogue->get('diff.empty', 'time'),
        ];
    }

    private function formatDateTime(int|string|DateTimeInterface $value): DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_int($value)) {
            return new DateTimeImmutable('@' . $value);
        }

        return new DateTimeImmutable($value);
    }
}
