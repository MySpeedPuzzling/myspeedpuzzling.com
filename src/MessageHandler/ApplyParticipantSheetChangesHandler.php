<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ParticipantSheetChangeReceipt;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\ParticipantImportPreviewStale;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\SheetChangesetIdTaken;
use SpeedPuzzling\Web\Message\ApplyParticipantSheetChanges;
use SpeedPuzzling\Web\Query\GetParticipantsSheetVersion;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\ParticipantSheetChangeReceiptRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\AppliedParticipantSheetChanges;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportApplier;
use SpeedPuzzling\Web\Services\ParticipantsSheet\SheetChangesPlanner;
use SpeedPuzzling\Web\Value\SheetChangeGroup;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Plans the whole change set first - read only, inside the transaction the middleware opened and under the event's
 * lock (SerializedByLock) - and only then writes the net difference through the import's applier, so a refused group
 * never leaves anything behind and nothing changes before everything is checked (the rolled-back-handler rule).
 *
 * A change set id seen before is answered from its receipt (ParticipantSheetChangeReceipt) and never applied again - a
 * resend whose answer got lost cannot bring back a value corrected since; an id of another event's change set is
 * refused (409). Every change set that is not a dry run leaves a receipt, whatever its groups did.
 *
 * `versionBefore` is the sheet's version under the lock before the plan, `versionAfter` the version after the write -
 * read after an explicit flush, still in the transaction and under the lock, so it is exactly this write's result.
 *
 * The receipt is also the change trail: who sent the change set and its groups as received, kept 90 days with it.
 */
#[AsMessageHandler]
readonly final class ApplyParticipantSheetChangesHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionRoundRepository $roundRepository,
        private ParticipantSheetChangeReceiptRepository $receiptRepository,
        private PlayerRepository $playerRepository,
        private GetParticipantsSheetVersion $getVersion,
        private SheetChangesPlanner $planner,
        private ParticipantImportApplier $applier,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     * @throws SheetChangesetIdTaken the id belongs to a change set of another event - nothing changed
     * @throws ParticipantImportPreviewStale something the plan names vanished outside the event's lock - nothing changed
     */
    public function __invoke(ApplyParticipantSheetChanges $message): AppliedParticipantSheetChanges
    {
        $competition = $this->competitionRepository->get($message->competitionId);
        $competitionId = $competition->id->toString();

        $changesetId = $message->changesetId;

        if ($message->dryRun === false) {
            if ($changesetId === null) {
                throw new \InvalidArgumentException('A change set that is not a dry run needs its id.');
            }

            $receipt = $this->receiptRepository->find($changesetId);

            if ($receipt !== null) {
                if ($receipt->competition->id->equals($competition->id) === false) {
                    throw new SheetChangesetIdTaken();
                }

                return AppliedParticipantSheetChanges::fromReceipt($receipt);
            }
        }

        // First, before the plan reads the event - under the lock nobody else writes in between
        $versionBefore = $this->getVersion->ofCompetition($competitionId);
        $plan = $this->planner->plan($competitionId, $message->groups, $versionBefore);

        if ($message->dryRun) {
            return new AppliedParticipantSheetChanges(
                dryRun: true,
                replayed: false,
                versionBefore: $versionBefore,
                versionAfter: $versionBefore,
                groups: $plan->groups,
            );
        }

        if ($plan->operations->isEmpty() === false) {
            $this->applier->applyOperations($competitionId, $plan->operations);
        }

        foreach ($plan->teamSizes as $roundId => $teamSize) {
            // Validated by the planner (TEAM_SIZE_MIN..TEAM_SIZE_MAX or null, team rounds of this event only)
            $this->roundRepository->get($roundId)->changeTeamSize($teamSize);
        }

        // The version after this write needs the write in the database - inside the middleware's transaction, still
        // under the lock, so it is exactly what this change set left
        $this->entityManager->flush();
        $versionAfter = $this->getVersion->ofCompetition($competitionId);

        $applied = new AppliedParticipantSheetChanges(
            dryRun: false,
            replayed: false,
            versionBefore: $versionBefore,
            versionAfter: $versionAfter,
            groups: $plan->groups,
        );

        $this->receiptRepository->save(new ParticipantSheetChangeReceipt(
            id: Uuid::fromString($changesetId),
            competition: $competition,
            receivedAt: $this->clock->now(),
            outcomes: $applied->outcomes(),
            versionBefore: $versionBefore,
            versionAfter: $versionAfter,
            actingPlayer: $this->actingPlayer($message->actingPlayerId),
            changes: array_map(static fn (SheetChangeGroup $group): array => $group->toArray(), $message->groups),
        ));

        return $applied;
    }

    /**
     * The organiser who sent the change set - an account deleted meanwhile leaves the trail without a name (the receipt
     * would lose it with the account anyway).
     */
    private function actingPlayer(string $playerId): null|Player
    {
        try {
            return $this->playerRepository->get($playerId);
        } catch (PlayerNotFound) {
            return null;
        }
    }
}
