<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Events\OfficialRoundResultsPublished;
use SpeedPuzzling\Web\Message\PublishCompetition;
use SpeedPuzzling\Web\Query\GetRoundsWithPublishedOfficialResults;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\Organizations\CompetitionSubmittedMailer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * docs/features/organizations/README.md "Drafts": a one-time event still waiting for approval is submitted (the admin is
 * e-mailed); an edition's approval is its series'. Official results published while it was hidden are told now that it
 * is public (as approving does). Publishing a published event changes nothing.
 */
#[AsMessageHandler]
readonly final class PublishCompetitionHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionSubmittedMailer $competitionSubmittedMailer,
        private GetRoundsWithPublishedOfficialResults $getRoundsWithPublishedOfficialResults,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(PublishCompetition $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);

        if ($competition->isDraft === false) {
            return;
        }

        $competition->publish();

        if ($competition->series === null && $competition->isApproved() === false && $competition->isRejected() === false) {
            $this->competitionSubmittedMailer->notifyAdmin(
                $competition->name,
                $competition->addedByPlayer->name ?? 'Unknown',
                $competition->location,
            );
        }

        if ($competition->isPubliclyVisible()) {
            foreach ($this->getRoundsWithPublishedOfficialResults->ofCompetition($competition->id->toString()) as $roundId) {
                $this->messageBus->dispatch(new OfficialRoundResultsPublished(Uuid::fromString($roundId)), [new DispatchAfterCurrentBusStamp()]);
            }
        }
    }
}
