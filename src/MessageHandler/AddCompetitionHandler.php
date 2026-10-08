<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\Exceptions\OrganizationNotManaged;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\Organizations\CompetitionSubmittedMailer;
use SpeedPuzzling\Web\Services\Organizations\OrganizationApprovalPolicy;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A one-time event waits for an admin's approval - unless it is created under an approved organization by its team
 * (OrganizationApprovalPolicy, docs/features/organizations/README.md "Approval"). The admin is e-mailed only for an
 * event that enters the approval queue now: not a draft (submitted when published), not approved at once.
 */
#[AsMessageHandler]
readonly final class AddCompetitionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private OrganizationRepository $organizationRepository,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
        private CompetitionSlugGenerator $slugGenerator,
        private OrganizationApprovalPolicy $organizationApprovalPolicy,
        private CompetitionSubmittedMailer $competitionSubmittedMailer,
    ) {
    }

    /**
     * @throws CompetitionSlugTaken
     * @throws InvalidCompetitionSlug
     * @throws OrganizationNotManaged
     */
    public function __invoke(AddCompetition $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $now = $this->clock->now();

        if ($message->slug !== null) {
            if (CompetitionSlugGenerator::isValid($message->slug) === false) {
                throw new InvalidCompetitionSlug($message->slug);
            }

            if ($this->slugGenerator->isTaken($message->slug, null)) {
                throw new CompetitionSlugTaken($message->slug);
            }
        }

        $organization = null;

        if ($message->organizationId !== null) {
            $organization = $this->organizationRepository->get($message->organizationId);

            if ($player->isAdmin === false && $organization->isOnTeam($player) === false) {
                throw new OrganizationNotManaged();
            }
        }

        $slug = $message->slug ?? $this->slugGenerator->generate($message->name);

        $logoPath = null;
        if ($message->logo !== null) {
            $extension = $message->logo->guessExtension();
            $timestamp = $now->getTimestamp();
            $logoPath = "competitions/{$message->competitionId}-{$timestamp}.{$extension}";

            $this->imageOptimizer->optimize($message->logo->getPathname());

            $stream = fopen($message->logo->getPathname(), 'rb');
            $this->filesystem->writeStream($logoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $competition = new Competition(
            id: $message->competitionId,
            name: $message->name,
            slug: $slug,
            shortcut: $message->shortcut,
            logo: $logoPath,
            description: $message->description,
            link: $message->link,
            registrationLink: $message->registrationLink,
            resultsLink: $message->resultsLink,
            location: $message->location,
            locationCountryCode: $message->locationCountryCode,
            dateFrom: $message->dateFrom,
            dateTo: $message->dateTo,
            tag: null,
            isOnline: $message->isOnline,
            addedByPlayer: $player,
            createdAt: $now,
            organization: $organization,
            isDraft: $message->isDraft,
            eligibility: $message->eligibility,
        );

        foreach ($message->maintainerIds as $maintainerId) {
            $maintainer = $this->playerRepository->get($maintainerId);
            $competition->maintainers->add($maintainer);
        }

        $approvedAtOnce = $this->organizationApprovalPolicy->approveIfUnderTrustedOrganization($competition, $player, $now);

        $this->entityManager->persist($competition);
        $this->entityManager->flush();

        if ($message->notifyAdmin === false || $message->isDraft || $approvedAtOnce) {
            return;
        }

        $this->competitionSubmittedMailer->notifyAdmin($message->name, $player->name ?? 'Unknown', $message->location);
    }
}
