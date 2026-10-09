<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class AddCompetition
{
    /**
     * @param array<string> $maintainerIds
     */
    public function __construct(
        public UuidInterface $competitionId,
        public string $playerId,
        public string $name,
        public null|string $shortcut,
        public null|string $description,
        public null|string $link,
        public null|string $registrationLink,
        public null|string $resultsLink,
        public null|string $location,
        public null|string $locationCountryCode,
        public null|DateTimeImmutable $dateFrom,
        public null|DateTimeImmutable $dateTo,
        public bool $isOnline,
        public null|UploadedFile $logo,
        public array $maintainerIds,
        // An explicitly chosen slug (validated unique) - null generates it from the name
        public null|string $slug = null,
        // The e-mail asking an admin to review the new competition - pointless when an admin creates it
        public bool $notifyAdmin = true,
        // docs/features/organizations/README.md: under an organization the creator is on the team of (admins: any) -
        // approved at once when the organization is approved (OrganizationApprovalPolicy)
        public null|string $organizationId = null,
        // "Who can enter"
        public null|string $eligibility = null,
        // "Save as draft" - only its team sees it, no admin e-mail until it is published
        public bool $isDraft = false,
    ) {
    }
}
