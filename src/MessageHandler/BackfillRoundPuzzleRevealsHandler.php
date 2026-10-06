<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Message\BackfillRoundPuzzleReveals;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Round puzzles saved before the reveal was kept on the round puzzle (2026-10) do not say whether the round created
 * their puzzle - so the round would not keep the puzzle's site-wide hide dates in step with its reveal. A puzzle the
 * round created got hide dates at the round's start when it was added; a secret round puzzle whose puzzle carries a
 * hide date within two days of the round's start is that puzzle (a placeholder's far-future date is not). The window
 * also catches the dates a round left behind when its start moved before this existed - that is the point: they are
 * set to the reveal moment the organiser is shown. Idempotent; a dry run changes nothing.
 */
#[AsMessageHandler]
readonly final class BackfillRoundPuzzleRevealsHandler
{
    private const int WINDOW_SECONDS = 2 * 24 * 3600;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<string> one line per round puzzle that (would) change
     */
    public function __invoke(BackfillRoundPuzzleReveals $message): array
    {
        /** @var array<CompetitionRoundPuzzle> $roundPuzzles */
        $roundPuzzles = $this->entityManager->createQueryBuilder()
            ->select('crp', 'r', 'p')
            ->from(CompetitionRoundPuzzle::class, 'crp')
            ->join('crp.round', 'r')
            ->join('crp.puzzle', 'p')
            ->where('crp.hideUntilRoundStarts = true')
            ->andWhere('crp.hidesEverywhere = false')
            ->orderBy('r.startsAt')
            ->getQuery()
            ->getResult();

        $changes = [];

        foreach ($roundPuzzles as $roundPuzzle) {
            $puzzle = $roundPuzzle->puzzle;
            $roundStartsAt = $roundPuzzle->round->startsAt;

            if (
                !self::isNear($puzzle->hideUntil, $roundStartsAt)
                && !self::isNear($puzzle->hideImageUntil, $roundStartsAt)
            ) {
                continue;
            }

            $before = sprintf('hide_until %s, hide_image_until %s', self::format($puzzle->hideUntil), self::format($puzzle->hideImageUntil));

            if ($message->dryRun === false) {
                $roundPuzzle->markHidesEverywhere();
            }

            $revealsAt = $roundPuzzle->revealsAt();

            $changes[] = sprintf(
                'round puzzle %s (puzzle %s "%s", round "%s" starting %s UTC): %s -> reveal %s',
                $roundPuzzle->id->toString(),
                $puzzle->id->toString(),
                $puzzle->name,
                $roundPuzzle->round->name,
                $roundStartsAt->format('Y-m-d H:i'),
                $before,
                $revealsAt === null ? 'manual' : $revealsAt->format('Y-m-d H:i') . ' UTC',
            );
        }

        return $changes;
    }

    private static function isNear(null|DateTimeImmutable $hiddenUntil, DateTimeImmutable $roundStartsAt): bool
    {
        return $hiddenUntil !== null
            && abs($hiddenUntil->getTimestamp() - $roundStartsAt->getTimestamp()) <= self::WINDOW_SECONDS;
    }

    private static function format(null|DateTimeImmutable $moment): string
    {
        return $moment === null ? 'none' : $moment->format('Y-m-d H:i');
    }
}
