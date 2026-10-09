<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\OrganizationKind;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * docs/features/organizations/README.md - any signed-in player creates an organization, waiting for approval (the
 * internal API creates approved ones: $approve).
 */
readonly final class AddOrganization
{
    /**
     * @param list<string> $socialLinks
     * @param list<string> $maintainerIds
     */
    public function __construct(
        public UuidInterface $organizationId,
        public string $playerId,
        public string $name,
        public null|string $shortName,
        public null|string $about,
        public null|string $website,
        public array $socialLinks,
        public null|string $countryCode,
        public null|string $region,
        public null|OrganizationKind $kind,
        public null|UploadedFile $logo,
        public array $maintainerIds,
        // An explicitly chosen slug (validated, unique among organizations) - null generates it from the name
        public null|string $slug = null,
        public bool $isDraft = false,
        // Approved by its creator at once, no admin e-mail - the internal API (its reviewer is an admin)
        public bool $approve = false,
    ) {
    }
}
