<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\Exceptions\OrganizationSlugTaken;
use SpeedPuzzling\Web\Exceptions\OrganizationTeamFull;
use SpeedPuzzling\Web\Message\EditOrganization;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Value\SocialLinks;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditOrganizationHandler
{
    public function __construct(
        private OrganizationRepository $organizationRepository,
        private PlayerRepository $playerRepository,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
        private CompetitionSlugGenerator $slugGenerator,
    ) {
    }

    /**
     * @throws InvalidCompetitionSlug
     * @throws OrganizationSlugTaken
     */
    public function __invoke(EditOrganization $message): void
    {
        $organization = $this->organizationRepository->get($message->organizationId);

        // A rename keeps the slug (published links know the organization by it) - only an explicitly chosen one changes it
        $slug = $organization->slug;

        if ($message->slug !== null) {
            if (CompetitionSlugGenerator::isValid($message->slug) === false) {
                throw new InvalidCompetitionSlug($message->slug);
            }

            if ($this->slugGenerator->isOrganizationSlugTaken($message->slug, $message->organizationId)) {
                throw new OrganizationSlugTaken($message->slug);
            }

            $slug = $message->slug;
        }

        // Validated before the logo is stored
        $socialLinks = SocialLinks::fromInput($message->socialLinks);
        $creatorId = $organization->addedByPlayer?->id->toString();
        $maintainerIds = Organization::teamIds($message->maintainerIds, $creatorId);

        if (count($maintainerIds) > Organization::MAX_MAINTAINERS) {
            throw new OrganizationTeamFull();
        }

        $logoPath = $organization->logo;
        if ($message->logo !== null) {
            $extension = $message->logo->guessExtension();
            $logoPath = "organizations/{$message->organizationId}-{$this->clock->now()->getTimestamp()}.{$extension}";

            $this->imageOptimizer->optimize($message->logo->getPathname());

            $stream = fopen($message->logo->getPathname(), 'rb');
            $this->filesystem->writeStream($logoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $organization->edit(
            name: $message->name,
            slug: $slug,
            shortName: $message->shortName,
            logo: $logoPath,
            about: $message->about,
            website: $message->website,
            socialLinks: $socialLinks,
            countryCode: $message->countryCode,
            region: $message->region,
            kind: $message->kind,
        );

        $organization->maintainers->clear();

        foreach ($maintainerIds as $maintainerId) {
            $organization->maintainers->add($this->playerRepository->get($maintainerId));
        }
    }
}
