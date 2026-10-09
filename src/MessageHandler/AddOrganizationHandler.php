<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\Exceptions\OrganizationSlugTaken;
use SpeedPuzzling\Web\Exceptions\OrganizationTeamFull;
use SpeedPuzzling\Web\Message\AddOrganization;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\Organizations\CompetitionSubmittedMailer;
use SpeedPuzzling\Web\Value\SocialLinks;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * docs/features/organizations/README.md "Approval": created waiting for approval (the admin is e-mailed unless it is a
 * draft - a draft is submitted by publishing it), or approved at once by the internal API.
 */
#[AsMessageHandler]
readonly final class AddOrganizationHandler
{
    public function __construct(
        private OrganizationRepository $organizationRepository,
        private PlayerRepository $playerRepository,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
        private CompetitionSlugGenerator $slugGenerator,
        private CompetitionSubmittedMailer $competitionSubmittedMailer,
    ) {
    }

    /**
     * @throws InvalidCompetitionSlug
     * @throws OrganizationSlugTaken
     */
    public function __invoke(AddOrganization $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $now = $this->clock->now();

        if ($message->slug !== null) {
            if (CompetitionSlugGenerator::isValid($message->slug) === false) {
                throw new InvalidCompetitionSlug($message->slug);
            }

            if ($this->slugGenerator->isOrganizationSlugTaken($message->slug)) {
                throw new OrganizationSlugTaken($message->slug);
            }
        }

        $slug = $message->slug ?? $this->slugGenerator->generateOrganizationSlug($message->name);
        $socialLinks = SocialLinks::fromInput($message->socialLinks);
        $maintainerIds = Organization::teamIds($message->maintainerIds, $player->id->toString());

        if (count($maintainerIds) > Organization::MAX_MAINTAINERS) {
            throw new OrganizationTeamFull();
        }

        $logoPath = null;
        if ($message->logo !== null) {
            $extension = $message->logo->guessExtension();
            $logoPath = "organizations/{$message->organizationId}-{$now->getTimestamp()}.{$extension}";

            $this->imageOptimizer->optimize($message->logo->getPathname());

            $stream = fopen($message->logo->getPathname(), 'rb');
            $this->filesystem->writeStream($logoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $organization = new Organization(
            id: $message->organizationId,
            name: $message->name,
            slug: $slug,
            createdAt: $now,
            shortName: $message->shortName,
            logo: $logoPath,
            about: $message->about,
            website: $message->website,
            links: $socialLinks,
            countryCode: $message->countryCode,
            region: $message->region,
            kind: $message->kind,
            isDraft: $message->isDraft,
            addedByPlayer: $player,
        );

        // The creator is on the team as its creator - never a maintainer row too (teamIds() leaves it out)
        foreach ($maintainerIds as $maintainerId) {
            $organization->maintainers->add($this->playerRepository->get($maintainerId));
        }

        if ($message->approve) {
            $organization->approve($player, $now);
        }

        // Created published: in the queue now (approve() marks it too) - a later publish e-mails nobody
        if ($message->isDraft === false) {
            $organization->markSubmitted($now);
        }

        $this->organizationRepository->save($organization);

        if ($message->approve === false && $message->isDraft === false) {
            $this->competitionSubmittedMailer->notifyAdminOfOrganization($message->name, $player->name ?? 'Unknown', $message->region);
        }
    }
}
