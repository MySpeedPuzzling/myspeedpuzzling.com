<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Message\AddEdition;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsMessageHandler]
readonly final class AddEditionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompetitionSeriesRepository $seriesRepository,
        private SluggerInterface $slugger,
    ) {
    }

    public function __invoke(AddEdition $message): void
    {
        $series = $this->seriesRepository->get($message->seriesId);

        $competition = new Competition(
            id: $message->competitionId,
            name: $message->name,
            slug: $this->generateUniqueSlug($message->name, $message->seriesId),
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: $message->registrationLink,
            resultsLink: $message->resultsLink,
            location: $series->location,
            locationCountryCode: $series->locationCountryCode,
            dateFrom: $message->dateFrom,
            dateTo: $message->dateTo,
            tag: null,
            isOnline: $series->isOnline,
            series: $series,
        );

        $this->entityManager->persist($competition);
    }

    private function generateUniqueSlug(string $name, string $seriesId): string
    {
        $slug = (string) $this->slugger->slug(strtolower($name));

        /** @var int|string $existingCount */
        $existingCount = $this->entityManager->getConnection()
            ->executeQuery(
                'SELECT COUNT(*) FROM competition WHERE slug = :slug AND series_id = :seriesId',
                ['slug' => $slug, 'seriesId' => $seriesId],
            )
            ->fetchOne();
        $existingCount = (int) $existingCount;

        if ($existingCount > 0) {
            $slug .= '-' . substr(md5(uniqid()), 0, 6);
        }

        return $slug;
    }
}
