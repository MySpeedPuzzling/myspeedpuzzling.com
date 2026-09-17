<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Message\BackfillCompetitionRoundSlugs;
use SpeedPuzzling\Web\Services\RoundResults\CompetitionRoundSlugGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Gives every round created before slugs existed one, through the same generator new rounds use, so old
 * and new slugs follow one policy. Rounds that already have a slug keep it. Idempotent.
 */
#[AsMessageHandler]
readonly final class BackfillCompetitionRoundSlugsHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompetitionRoundSlugGenerator $slugGenerator,
    ) {
    }

    /**
     * @return int number of rounds that got a slug
     */
    public function __invoke(BackfillCompetitionRoundSlugs $message): int
    {
        /** @var array<CompetitionRound> $rounds */
        $rounds = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(CompetitionRound::class, 'r')
            ->orderBy('r.startsAt')
            ->addOrderBy('r.id')
            ->getQuery()
            ->getResult();

        /** @var array<string, array<string>> $takenSlugsPerCompetition */
        $takenSlugsPerCompetition = [];

        foreach ($rounds as $round) {
            if ($round->slug !== null) {
                $takenSlugsPerCompetition[$round->competition->id->toString()][] = $round->slug;
            }
        }

        $assigned = 0;

        foreach ($rounds as $round) {
            if ($round->slug !== null) {
                continue;
            }

            $competitionId = $round->competition->id->toString();
            $slug = $this->slugGenerator->generate($round->name, $takenSlugsPerCompetition[$competitionId] ?? []);

            $round->assignSlug($slug);
            $takenSlugsPerCompetition[$competitionId][] = $slug;
            $assigned++;
        }

        return $assigned;
    }
}
