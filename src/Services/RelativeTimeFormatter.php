<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class RelativeTimeFormatter
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function formatDiff(
        int|string|DateTimeInterface $from,
        null|int|string|DateTimeInterface $to = null,
        null|string $locale = null,
    ): string {
        $from = self::formatDateTime($from);
        $to = self::formatDateTime($to);

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

    private static function formatDateTime(null|int|string|DateTimeInterface $value): DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_int($value)) {
            $value = date('Y-m-d H:i:s', $value);
        }

        if (null === $value) {
            $value = 'now';
        }

        return new \DateTime($value);
    }
}
