<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\OrganizationKind;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class EditOrganization
{
    /**
     * @param list<string> $socialLinks
     * @param list<string> $maintainerIds the whole team besides its creator - replaces the maintainers
     */
    public function __construct(
        public string $organizationId,
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
        // An explicitly chosen slug (validated, unique among organizations) - null keeps the slug, also on a rename
        public null|string $slug = null,
    ) {
    }
}
