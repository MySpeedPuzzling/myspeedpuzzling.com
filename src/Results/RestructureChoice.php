<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One option of a restructuring page's select (docs/features/organizations/README.md "Restructuring tools"): a series
 * an edition can move to, or a competition a round can move to.
 */
readonly final class RestructureChoice
{
    public function __construct(
        public string $id,
        public string $name,
        // The series of an edition, the organization of a series - whatever tells two of the same name apart
        public null|string $context,
        // The calendar day of a competition (`2026-10-06`), null for a series or an undated competition
        public null|string $date,
        public bool $isDraft,
    ) {
    }

    /**
     * "Name · context · 2026-10-06" - the date as ISO, unambiguous in every language, with non-breaking hyphens so a
     * narrow picker never breaks it
     */
    public function label(): string
    {
        $date = $this->date !== null ? str_replace('-', "\u{2011}", $this->date) : null;

        return implode(' · ', array_filter([$this->name, $this->context, $date], static fn (null|string $part): bool => $part !== null && $part !== ''));
    }
}
