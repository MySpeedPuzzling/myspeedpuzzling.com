<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayLimitReached;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Repository\CompetitionDisplayRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\DisplayDesign;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A new display follows the current round and starts from the blank design (D-displays-overlays.md §3.3).
 */
#[AsMessageHandler]
readonly final class AddCompetitionDisplayHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionDisplayRepository $displayRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionDisplayLimitReached
     */
    public function __invoke(AddCompetitionDisplay $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);

        // Counted under the event's displays lock (AddCompetitionDisplay is SerializedByLock) - never one over the cap
        if ($this->displayRepository->countOf($competition) >= CompetitionDisplay::MAX_PER_COMPETITION) {
            throw new CompetitionDisplayLimitReached();
        }

        $now = $this->clock->now();

        $this->displayRepository->save(new CompetitionDisplay(
            id: $message->displayId,
            competition: $competition,
            kind: $message->kind,
            name: CompetitionDisplay::cleanName($message->name),
            token: OnlineToken::generate(),
            locale: CompetitionDisplay::checkLocale($message->locale),
            design: DisplayDesign::blank($message->kind),
            createdAt: $now,
            createdBy: $message->actorPlayerId !== null ? $this->playerRepository->get($message->actorPlayerId) : null,
            updatedAt: $now,
        ));
    }
}
