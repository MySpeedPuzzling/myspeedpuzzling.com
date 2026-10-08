<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Value\OrganizationKind;
use SpeedPuzzling\Web\Value\SocialLinks;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The add/edit organization forms (OrganizationFormType, workstream A) and the internal API's organization input
 * (workstream D) - docs/features/organizations/README.md, P13/P14.
 */
final class OrganizationFormData
{
    /**
     * @param list<string> $socialLinks
     * @param list<string> $maintainers
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public null|string $name = null,
        #[Assert\Length(max: 30)]
        public null|string $shortName = null,
        #[Assert\Length(max: 5000)]
        public null|string $about = null,
        #[Assert\Url(protocols: ['http', 'https'])]
        #[Assert\Length(max: 255)]
        public null|string $website = null,
        #[Assert\Count(max: SocialLinks::MAX, maxMessage: 'organization_fields.social_links_too_many')]
        #[Assert\All([
            new Assert\Url(protocols: ['http', 'https'], message: 'organization_fields.social_link_invalid'),
            new Assert\Length(max: 255),
        ])]
        public array $socialLinks = [],
        public null|string $countryCode = null,
        #[Assert\Length(max: 120)]
        public null|string $region = null,
        public null|OrganizationKind $kind = null,
        public null|UploadedFile $logo = null,
        public array $maintainers = [],
        // The "URL" field of the edit form
        public null|string $slug = null,
    ) {
    }

    public static function fromOrganization(Organization $organization): self
    {
        $data = new self();
        $data->name = $organization->name;
        $data->shortName = $organization->shortName;
        $data->about = $organization->about;
        $data->website = $organization->website;
        $data->socialLinks = $organization->socialLinks;
        $data->countryCode = $organization->countryCode;
        $data->region = $organization->region;
        $data->kind = $organization->kind;
        $data->slug = $organization->slug;

        $maintainerIds = [];

        foreach ($organization->maintainers as $maintainer) {
            $maintainerIds[] = $maintainer->id->toString();
        }

        $data->maintainers = $maintainerIds;

        return $data;
    }
}
