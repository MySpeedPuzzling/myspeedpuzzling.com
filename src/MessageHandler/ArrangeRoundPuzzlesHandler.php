<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Message\ArrangeRoundPuzzles;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Value\RoundPuzzleLabel;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Every puzzle of the round gets its place 1..N: the listed ones in the listed order, then the others in the order they
 * had (RoundPuzzleLabel). Labels are checked before anything changes (at most RoundPuzzleLabel::MAX_LENGTH characters).
 */
#[AsMessageHandler]
readonly final class ArrangeRoundPuzzlesHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound also a round of another event than the caller authorised
     * @throws \InvalidArgumentException a label too long
     */
    public function __invoke(ArrangeRoundPuzzles $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        if ($round->competition->id->toString() !== strtolower($message->competitionId)) {
            throw new CompetitionRoundNotFound();
        }

        $current = RoundPuzzleLabel::sort(
            array_values($round->roundPuzzles->toArray()),
            static fn (CompetitionRoundPuzzle $roundPuzzle): array => [$roundPuzzle->position, $roundPuzzle->id->toString()],
        );

        $byId = [];
        foreach ($current as $roundPuzzle) {
            $byId[$roundPuzzle->id->toString()] = $roundPuzzle;
        }

        $ordered = [];
        foreach ($message->roundPuzzleIds as $roundPuzzleId) {
            $roundPuzzleId = strtolower($roundPuzzleId);

            if (isset($byId[$roundPuzzleId])) {
                $ordered[$roundPuzzleId] = $byId[$roundPuzzleId];
            }
        }

        foreach ($byId as $roundPuzzleId => $roundPuzzle) {
            $ordered[$roundPuzzleId] ??= $roundPuzzle;
        }

        // Checked for every puzzle first - nothing changes when one label is refused
        $labels = [];
        foreach ($ordered as $roundPuzzleId => $roundPuzzle) {
            $labels[$roundPuzzleId] = array_key_exists($roundPuzzleId, $message->labels)
                ? RoundPuzzleLabel::normalise($message->labels[$roundPuzzleId])
                : $roundPuzzle->label;
        }

        $position = 1;
        foreach ($ordered as $roundPuzzleId => $roundPuzzle) {
            $roundPuzzle->placeAt($position++, $labels[$roundPuzzleId]);
        }
    }
}
