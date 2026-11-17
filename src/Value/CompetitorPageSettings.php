<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A competition's competitor pages & QR settings (docs/features/competitions-management/toolkit/
 * B-qr-competitor-page.md §2) - the four competition columns, written only through
 * Competition::changeCompetitorPageSettings(). The defaults are the behaviour of every competition before the toolkit.
 *
 * Always normalised: time proposals need the competitor pages (pages off ⇒ proposals off).
 */
final readonly class CompetitorPageSettings
{
    public bool $timeProposals;

    public function __construct(
        // Public competitor pages exist; a tag scanned by somebody who is not an organiser or referee opens one
        public bool $competitorPages = false,
        // Confirmed results shown on competitor pages before the round's results are published (never a rank)
        public bool $liveResultsBeforePublishing = false,
        bool $timeProposals = false,
        // Null = not chosen (person name tags, as before)
        public null|QrTagMode $qrTagMode = null,
    ) {
        $this->timeProposals = $competitorPages && $timeProposals;
    }

    public static function defaults(): self
    {
        return new self();
    }
}
