<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CannotUnpublish;
use SpeedPuzzling\Web\Message\UnpublishCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\Drafts\UnpublishBlockers;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Back to draft only while nobody joined it and no result or solving time is linked to it (UnpublishBlockers) - checked
 * under the event's participants lock (the message is SerializedByLock).
 */
#[AsMessageHandler]
readonly final class UnpublishCompetitionHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private UnpublishBlockers $unpublishBlockers,
    ) {
    }

    /**
     * @throws CannotUnpublish
     */
    public function __invoke(UnpublishCompetition $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);
        $check = $this->unpublishBlockers->forCompetition($competition->id->toString());

        if ($check->allowed() === false) {
            throw new CannotUnpublish($check->blockers());
        }

        $competition->unpublish();
    }
}
