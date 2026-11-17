<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayLimitReached;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayNotFound;
use SpeedPuzzling\Web\Message\DuplicateCompetitionDisplay;
use SpeedPuzzling\Web\Repository\CompetitionDisplayRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The copy shares the design's image paths with its source (same event prefix - D7 counts the references, so deleting
 * one display keeps the files the other still shows).
 */
#[AsMessageHandler]
readonly final class DuplicateCompetitionDisplayHandler
{
    public function __construct(
        private CompetitionDisplayRepository $displayRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionDisplayNotFound
     * @throws CompetitionDisplayLimitReached
     */
    public function __invoke(DuplicateCompetitionDisplay $message): void
    {
        $source = $this->displayRepository->get($message->sourceDisplayId);

        // The lock this message holds is of $competitionId - a display of another event is not copied under it
        if (strtolower($source->competition->id->toString()) !== strtolower($message->competitionId)) {
            throw new CompetitionDisplayNotFound();
        }

        if ($this->displayRepository->countOf($source->competition) >= CompetitionDisplay::MAX_PER_COMPETITION) {
            throw new CompetitionDisplayLimitReached();
        }

        $this->displayRepository->save($source->duplicateAs(
            id: $message->newDisplayId,
            name: CompetitionDisplay::cleanName($message->name),
            token: OnlineToken::generate(),
            by: $message->actorPlayerId !== null ? $this->playerRepository->get($message->actorPlayerId) : null,
            at: $this->clock->now(),
        ));
    }
}
