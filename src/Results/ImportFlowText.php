<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One sentence of the import's guided flow (Services\DataImport\ImportFlow), not translated yet: the message key,
 * the number that picks its plural form, the other numbers it names and a day. The template formats the numbers
 * and the day for the page's language (data_import/_flow_text.html.twig) - nothing of a file is ever in it.
 */
readonly final class ImportFlowText
{
    /**
     * @param array<string, int> $numbers by placeholder name, without the percent signs
     */
    public function __construct(
        public string $key,
        public null|int $count = null,
        public array $numbers = [],
        public null|DateTimeImmutable $date = null,
    ) {
    }
}
