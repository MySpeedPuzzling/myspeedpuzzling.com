<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class PlatformChange
{
    public function __construct(
        public DateTimeImmutable $date,
        public string $title,
        public null|string $text,
    ) {
    }

    /**
     * @param array{
     *      date: string,
     *      title: string,
     *      text: null|string,
     *  } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            date: new DateTimeImmutable($row['date']),
            title: $row['title'],
            text: $row['text'],
        );
    }
}
