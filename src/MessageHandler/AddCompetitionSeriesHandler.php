<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\Exceptions\OrganizationNotManaged;
use SpeedPuzzling\Web\Message\AddCompetitionSeries;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Services\Organizations\CompetitionSubmittedMailer;
use SpeedPuzzling\Web\Services\Organizations\OrganizationApprovalPolicy;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * A series waits for an admin's approval - unless it is created under an approved organization by its team
 * (OrganizationApprovalPolicy, docs/features/organizations/README.md "Approval"). The admin is e-mailed only for a
 * series that enters the approval queue now: not a draft, not approved at once, and not created by an admin.
 */
#[AsMessageHandler]
readonly final class AddCompetitionSeriesHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private OrganizationRepository $organizationRepository,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
        private SluggerInterface $slugger,
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
    public function __invoke(AddCompetitionSeries $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $now = $this->clock->now();

        if ($message->slug !== null) {
            if (CompetitionSlugGenerator::isValid($message->slug) === false) {
                throw new InvalidCompetitionSlug($message->slug);
            }

            if ($this->slugGenerator->isSeriesSlugTaken($message->slug)) {
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

        $slug = $message->slug ?? $this->generateUniqueSlug($message->name);

        $logoPath = null;
        if ($message->logo !== null) {
            $extension = $message->logo->guessExtension();
            $timestamp = $now->getTimestamp();
            $logoPath = "competitions/{$message->seriesId}-{$timestamp}.{$extension}";

            $this->imageOptimizer->optimize($message->logo->getPathname());

            $stream = fopen($message->logo->getPathname(), 'rb');
            $this->filesystem->writeStream($logoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $series = new CompetitionSeries(
            id: $message->seriesId,
            name: $message->name,
            slug: $slug,
            logo: $logoPath,
            description: $message->description,
            link: $message->link,
            isOnline: $message->isOnline,
            location: $message->location,
            locationCountryCode: $message->locationCountryCode,
            shortcut: $message->shortcut,
            addedByPlayer: $player,
            createdAt: $now,
            organization: $organization,
            isDraft: $message->isDraft,
            eligibility: $message->eligibility,
            schedule: $message->schedule,
        );

        foreach ($message->maintainerIds as $maintainerId) {
            $maintainer = $this->playerRepository->get($maintainerId);
            $series->maintainers->add($maintainer);
        }

        $approvedAtOnce = $this->organizationApprovalPolicy->approveIfUnderTrustedOrganization($series, $player, $now);

        $this->entityManager->persist($series);
        $this->entityManager->flush();

        if ($message->notifyAdmin === false || $message->isDraft || $approvedAtOnce) {
            return;
        }

        $this->competitionSubmittedMailer->notifyAdmin($message->name, $player->name ?? 'Unknown', $message->location ?? 'Online');
    }

    private function generateUniqueSlug(string $name): string
    {
        $slug = (string) $this->slugger->slug(strtolower($name));

        /** @var int|string $existingCount */
        $existingCount = $this->entityManager->getConnection()
            ->executeQuery(
                'SELECT COUNT(*) FROM competition_series WHERE slug = :slug',
                ['slug' => $slug],
            )
            ->fetchOne();
        $existingCount = (int) $existingCount;

        if ($existingCount > 0) {
            $slug .= '-' . substr(md5(uniqid()), 0, 6);
        }

        return $slug;
    }
}
