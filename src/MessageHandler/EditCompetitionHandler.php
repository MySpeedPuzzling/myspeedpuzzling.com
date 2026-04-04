<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\EditCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsMessageHandler]
readonly final class EditCompetitionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompetitionRepository $competitionRepository,
        private PlayerRepository $playerRepository,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
        private SluggerInterface $slugger,
    ) {
    }

    public function __invoke(EditCompetition $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);

        $logoPath = $competition->logo;
        if ($message->logo !== null) {
            $extension = $message->logo->guessExtension();
            $timestamp = $this->clock->now()->getTimestamp();
            $logoPath = "competitions/{$message->competitionId}-{$timestamp}.{$extension}";

            $this->imageOptimizer->optimize($message->logo->getPathname());

            $stream = fopen($message->logo->getPathname(), 'rb');
            $this->filesystem->writeStream($logoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $slug = $competition->slug;
        if ($competition->name !== $message->name) {
            $slug = $this->generateUniqueSlug($message->name, $message->competitionId);
        }

        $competition->edit(
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
            isOnline: $message->isOnline,
            isRecurring: $message->isRecurring,
        );

        // Sync maintainers
        $competition->maintainers->clear();
        foreach ($message->maintainerIds as $maintainerId) {
            $maintainer = $this->playerRepository->get($maintainerId);
            $competition->maintainers->add($maintainer);
        }
    }

    private function generateUniqueSlug(string $name, string $competitionId): string
    {
        $slug = (string) $this->slugger->slug(strtolower($name));

        /** @var int|string $existingCount */
        $existingCount = $this->entityManager->getConnection()
            ->executeQuery(
                'SELECT COUNT(*) FROM competition WHERE slug = :slug AND id != :id',
                ['slug' => $slug, 'id' => $competitionId],
            )
            ->fetchOne();
        $existingCount = (int) $existingCount;

        if ($existingCount > 0) {
            $slug .= '-' . substr(md5(uniqid()), 0, 6);
        }

        return $slug;
    }
}
