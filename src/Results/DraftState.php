<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Why a page is hidden as a draft (docs/features/organizations/README.md "Drafts") - the draft banner and the draft
 * guard of the detail pages read it. `isDraft` = the item itself; `seriesId` / `seriesName` = an edition whose series is
 * a draft (README P7) - both can apply.
 */
readonly final class DraftState
{
    public const string KIND_ORGANIZATION = 'organization';
    public const string KIND_SERIES = 'series';
    public const string KIND_COMPETITION = 'competition';

    /**
     * @param 'organization'|'series'|'competition' $kind
     */
    public function __construct(
        public string $kind,
        public string $id,
        public string $name,
        public bool $isDraft,
        public null|string $seriesId = null,
        public null|string $seriesName = null,
    ) {
    }

    public function isHidden(): bool
    {
        return $this->isDraft || $this->seriesId !== null;
    }
}
