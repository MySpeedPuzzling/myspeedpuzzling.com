<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionDisplayControl;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\DisplayControlRefused;
use SpeedPuzzling\Web\Message\SetCompetitionDisplayMessage;
use SpeedPuzzling\Web\Repository\CompetitionDisplayControlRepository;
use SpeedPuzzling\Web\Repository\CompetitionDisplayRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\DisplayControlMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sets or clears the message of an event's displays (competition toolkit v2 L1, displays-standings.md §5.1): one line,
 * at most CompetitionDisplayControl::MESSAGE_MAX_LENGTH characters (longer is refused, never cut), only for an event
 * with a display. Everything is checked before anything changes. The controller signals the displays after the commit
 * (CompetitionDisplayLiveUpdates::controlChanged()).
 */
#[AsMessageHandler]
readonly final class SetCompetitionDisplayMessageHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionDisplayRepository $displayRepository,
        private CompetitionDisplayControlRepository $controlRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     * @throws DisplayControlRefused
     */
    public function __invoke(SetCompetitionDisplayMessage $message): DisplayControlMessage
    {
        $competition = $this->competitionRepository->get($message->competitionId);
        $text = $message->text !== null ? CompetitionDisplayControl::cleanMessage($message->text) : '';

        if (mb_strlen($text) > CompetitionDisplayControl::MESSAGE_MAX_LENGTH) {
            throw new DisplayControlRefused(DisplayControlRefused::TOO_LONG);
        }

        if ($this->displayRepository->countOf($competition) === 0) {
            throw new DisplayControlRefused(DisplayControlRefused::NO_DISPLAYS);
        }

        $control = $this->controlRepository->find($competition->id->toString());

        // Clearing what was never set - nothing to write
        if ($control === null && $text === '') {
            return new DisplayControlMessage(null, []);
        }

        $now = $this->clock->now();
        $actor = $message->actorPlayerId !== null ? $this->playerRepository->get($message->actorPlayerId) : null;

        if ($control === null) {
            $control = new CompetitionDisplayControl($competition, $now);
            $this->controlRepository->save($control);
        }

        if ($text === '') {
            $control->clearMessage($actor, $now);
        } else {
            $control->setMessage($text, $actor, $now);
        }

        return new DisplayControlMessage($control->message, $control->recentMessages);
    }
}
