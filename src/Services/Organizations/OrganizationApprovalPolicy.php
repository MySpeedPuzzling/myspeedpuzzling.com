<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Organizations;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Events\OfficialRoundResultsPublished;
use SpeedPuzzling\Web\Query\GetRoundsWithPublishedOfficialResults;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * The one place of the rule "under a trusted organization, no admin approval" (docs/features/organizations/README.md
 * "Approval", D2 + P2) - called explicitly by every handler that creates or moves a series or a one-time event
 * (AddCompetition, AddCompetitionSeries, AssignEventToOrganization, CreateOrganizationFromSeries) and by
 * ApproveOrganization. Approval stays a plain approved_at: the ~25 readers of approval need no change.
 */
readonly final class OrganizationApprovalPolicy
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionSeriesRepository $competitionSeriesRepository,
        private GetRoundsWithPublishedOfficialResults $getRoundsWithPublishedOfficialResults,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * D2: a series or one-time event under an approved, not rejected organization, put there by a member of its team
     * (or an admin), is approved at once when it waits for approval. A rejected item stays rejected; an edition is never
     * approved on its own (its series is); a draft is approved too - it stays hidden until published.
     *
     * @return bool whether it approved the item
     */
    public function approveIfUnderTrustedOrganization(Competition|CompetitionSeries $item, Player $actor, DateTimeImmutable $now): bool
    {
        $organization = $item->organization;

        if ($organization === null || $organization->isApproved() === false || $organization->isRejected()) {
            return false;
        }

        if ($item instanceof Competition && $item->series !== null) {
            return false;
        }

        if ($item->isApproved() || $item->isRejected()) {
            return false;
        }

        if ($actor->isAdmin === false && $organization->isOnTeam($actor) === false) {
            return false;
        }

        $item->approve($actor, $now);
        $this->tellOfficialResults($item);

        return true;
    }

    /**
     * P2: approving an organization approves its series and one-time events waiting for approval - not the rejected
     * ones. Call it after approving the organization itself.
     */
    public function approvePendingItemsOf(Organization $organization, Player $approver, DateTimeImmutable $now): void
    {
        $items = [
            ...$this->competitionSeriesRepository->listByOrganization($organization),
            ...$this->competitionRepository->listOneTimeByOrganization($organization),
        ];

        foreach ($items as $item) {
            if ($item->isApproved() || $item->isRejected()) {
                continue;
            }

            $item->approve($approver, $now);
            $this->tellOfficialResults($item);
        }
    }

    /**
     * Official results published while the event was not public - the players are told once it is (as approving does,
     * ApproveCompetitionHandler); nothing while it stays hidden as a draft (publishing it tells them then)
     */
    private function tellOfficialResults(Competition|CompetitionSeries $item): void
    {
        if ($item->isPubliclyVisible() === false) {
            return;
        }

        $roundIds = $item instanceof Competition
            ? $this->getRoundsWithPublishedOfficialResults->ofCompetition($item->id->toString())
            : $this->getRoundsWithPublishedOfficialResults->ofSeries($item->id->toString());

        foreach ($roundIds as $roundId) {
            $this->messageBus->dispatch(new OfficialRoundResultsPublished(Uuid::fromString($roundId)), [new DispatchAfterCurrentBusStamp()]);
        }
    }
}
