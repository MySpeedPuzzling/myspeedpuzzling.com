<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResultReviewContact;
use SpeedPuzzling\Web\Message\PlanResultReviewEmails;
use SpeedPuzzling\Web\Query\GetResultReviewEmailCandidates;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\ResultReviewContactRepository;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultReviewContactPlanner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The daily planning of "Your results" e-mails (docs/features/duplicate-results.md, "Sending"): a `planned`
 * contact for everybody the rules allow. Sends nothing - SendPlannedResultReviewEmails paces that.
 * Running it twice plans nothing new: a player with a planned e-mail is not a candidate.
 */
#[AsMessageHandler]
readonly final class PlanResultReviewEmailsHandler
{
    public function __construct(
        private GetResultReviewEmailCandidates $getResultReviewEmailCandidates,
        private ResultReviewContactPlanner $planner,
        private PlayerRepository $playerRepository,
        private ResultReviewContactRepository $contactRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int how many e-mails were planned
     */
    public function __invoke(PlanResultReviewEmails $message): int
    {
        $now = $this->clock->now();
        $planned = 0;

        foreach ($this->getResultReviewEmailCandidates->all() as $candidate) {
            $plan = $this->planner->plan($candidate, $now);

            if ($plan === null) {
                continue;
            }

            $this->contactRepository->save(new ResultReviewContact(
                id: Uuid::uuid7(),
                player: $this->playerRepository->get($candidate->playerId),
                type: $plan->type,
                priority: $plan->priority,
                lastActiveOn: $candidate->lastActiveOn,
                caseIds: $plan->caseIds,
                removalIds: $plan->removalIds,
                plannedAt: $now,
            ));
            $planned++;
        }

        return $planned;
    }
}
