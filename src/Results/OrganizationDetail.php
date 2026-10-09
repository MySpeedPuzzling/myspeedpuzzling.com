<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\OrganizationKind;
use SpeedPuzzling\Web\Value\SocialLink;

/**
 * One organization in any state (GetOrganization, the approval queue of GetOrganizations) - the page decides what a
 * viewer may see. `addedByPlayerName` only in the approval queue.
 */
readonly final class OrganizationDetail
{
    /**
     * @param list<SocialLink> $socialLinks
     */
    public function __construct(
        public string $id,
        public string $name,
        public null|string $shortName,
        public string $slug,
        public null|string $logo,
        public null|string $about,
        public null|string $website,
        public array $socialLinks,
        public null|CountryCode $countryCode,
        public null|string $region,
        public null|OrganizationKind $kind,
        public bool $isDraft,
        public null|DateTimeImmutable $approvedAt,
        public null|DateTimeImmutable $rejectedAt,
        public null|string $rejectionReason,
        public null|string $addedByPlayerId,
        public null|string $addedByPlayerName,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * IsOrganizationPubliclyVisible: approved, not rejected, not a draft
     */
    public function isPublic(): bool
    {
        return $this->approvedAt !== null && $this->rejectedAt === null && $this->isDraft === false;
    }

    /**
     * Waiting for approval: neither approved nor rejected (a draft too - it is submitted by publishing it)
     */
    public function isPending(): bool
    {
        return $this->approvedAt === null && $this->rejectedAt === null;
    }
}
