<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayNotFound;
use SpeedPuzzling\Web\Message\ChangeCompetitionDisplayFollow;
use SpeedPuzzling\Web\Repository\CompetitionDisplayRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Value\DisplayFollowMode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Every round id is checked against the display's own event, loaded from the database - a round of another event is
 * "not found", never followed.
 */
#[AsMessageHandler]
readonly final class ChangeCompetitionDisplayFollowHandler
{
    public function __construct(
        private CompetitionDisplayRepository $displayRepository,
        private CompetitionRoundRepository $roundRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionDisplayNotFound
     */
    public function __invoke(ChangeCompetitionDisplayFollow $message): void
    {
        $display = $this->displayRepository->get($message->displayId);

        $rounds = [];

        foreach ($this->roundRepository->ofCompetition($display->competition) as $round) {
            $rounds[strtolower($round->id->toString())] = $round;
        }

        if ($message->mode === DisplayFollowMode::Pinned) {
            if ($message->pinnedRoundId === null) {
                throw new InvalidArgumentException('A pinned display needs its round.');
            }

            $pinnedRound = $rounds[strtolower($message->pinnedRoundId)] ?? throw new CompetitionDisplayNotFound();

            $display->follow(DisplayFollowMode::Pinned, $pinnedRound, null, $this->clock->now());

            return;
        }

        // The subset in the event's round order, each round once; none (or every round) = follow all of them
        $chosen = array_map('strtolower', $message->roundIds);

        foreach ($chosen as $roundId) {
            if (!isset($rounds[$roundId])) {
                throw new CompetitionDisplayNotFound();
            }
        }

        $subset = array_values(array_filter(array_keys($rounds), static fn (string $roundId): bool => in_array($roundId, $chosen, true)));

        $display->follow(
            DisplayFollowMode::Current,
            null,
            $subset === [] || count($subset) === count($rounds) ? null : $subset,
            $this->clock->now(),
        );
    }
}
