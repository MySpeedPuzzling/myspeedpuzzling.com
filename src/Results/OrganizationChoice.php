<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One option of the "Organization" select of the event and series forms (GetOrganizations::choicesForPlayer() /
 * allChoices()) - draft and pending ones are marked in the label.
 */
readonly final class OrganizationChoice
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $isDraft,
        public bool $isApproved,
    ) {
    }
}
