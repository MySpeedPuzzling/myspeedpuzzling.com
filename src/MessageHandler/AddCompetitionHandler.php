<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsMessageHandler]
readonly final class AddCompetitionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
        private SluggerInterface $slugger,
    ) {
    }

    public function __invoke(AddCompetition $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $now = $this->clock->now();

        $slug = $this->generateUniqueSlug($message->name);

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
        );

        foreach ($message->maintainerIds as $maintainerId) {
            $maintainer = $this->playerRepository->get($maintainerId);
            $competition->maintainers->add($maintainer);
        }

        $this->entityManager->persist($competition);
    }

    private function generateUniqueSlug(string $name): string
    {
        $slug = (string) $this->slugger->slug(strtolower($name));

        /** @var int|string $existingCount */
        $existingCount = $this->entityManager->getConnection()
            ->executeQuery(
                'SELECT COUNT(*) FROM competition WHERE slug = :slug',
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
