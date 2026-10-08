<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\Message\AddEdition;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsMessageHandler]
readonly final class AddEditionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompetitionSeriesRepository $seriesRepository,
        private SluggerInterface $slugger,
        private CompetitionSlugGenerator $slugGenerator,
    ) {
    }

    /**
     * @throws CompetitionSlugTaken
     * @throws InvalidCompetitionSlug
     */
    public function __invoke(AddEdition $message): void
    {
        $series = $this->seriesRepository->get($message->seriesId);

        if ($message->slug !== null) {
            if (CompetitionSlugGenerator::isValid($message->slug) === false) {
                throw new InvalidCompetitionSlug($message->slug);
            }

            if ($this->slugGenerator->isTaken($message->slug, $message->seriesId)) {
                throw new CompetitionSlugTaken($message->slug);
            }
        }

        $competition = new Competition(
            id: $message->competitionId,
            name: $message->name,
            slug: $message->slug ?? $this->generateUniqueSlug($message->name, $message->seriesId),
            shortcut: null,
            logo: null,
            description: self::emptyToNull($message->description),
            link: self::emptyToNull($message->link),
            registrationLink: $message->registrationLink,
            resultsLink: $message->resultsLink,
            location: $series->location,
            locationCountryCode: $series->locationCountryCode,
            dateFrom: $message->dateFrom,
            dateTo: $message->dateTo,
            tag: null,
            isOnline: $series->isOnline,
            series: $series,
            isDraft: $message->isDraft,
            eligibility: self::emptyToNull($message->eligibility),
        );

        $this->entityManager->persist($competition);
    }

    private static function emptyToNull(null|string $value): null|string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
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
