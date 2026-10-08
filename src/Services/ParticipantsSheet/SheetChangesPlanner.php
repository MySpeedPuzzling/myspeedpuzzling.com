<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantsSheet;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Services\ParticipantImport\SiteSnapshotReader;
use SpeedPuzzling\Web\Services\ParticipantsSheet\Plan\SheetPlan;
use SpeedPuzzling\Web\Services\ParticipantsSheet\Plan\SheetPlanRun;
use SpeedPuzzling\Web\Value\SheetChange;
use SpeedPuzzling\Web\Value\SheetChangeGroup;
use SpeedPuzzling\Web\Value\SheetChangeOp;

/**
 * What a participants sheet change set would do - read only (DBAL reads, no entity is touched), under the event's lock
 * when the handler calls it: the event as SiteSnapshotReader reads it for the import too, the groups evaluated in order
 * against it (SheetPlanRun), and the net difference written as the import's operations
 * (docs/features/competitions-management/participants-spreadsheet.md §6). A constant number of statements for any
 * number of groups.
 */
readonly final class SheetChangesPlanner
{
    public function __construct(
        private SiteSnapshotReader $siteSnapshotReader,
        private Connection $database,
    ) {
    }

    /**
     * @param list<SheetChangeGroup> $groups
     * @param string $stateVersion GetParticipantsSheetVersion, read by the caller before this
     *
     * @throws CompetitionNotFound
     */
    public function plan(string $competitionId, array $groups, string $stateVersion): SheetPlan
    {
        // Read here, under the event's lock - never from an entity loaded before it (the controller's, the identity
        // map's): an organiser switching management on or off meanwhile decides whether a restore keeps the waitlist
        $registrationManaged = $this->database->fetchOne(
            'SELECT registration_managed FROM competition WHERE id = :competitionId',
            ['competitionId' => $competitionId],
        );

        if (!is_bool($registrationManaged)) {
            throw new CompetitionNotFound();
        }

        $playerIds = [];
        $newParticipantIds = [];
        $teamIds = [];

        foreach ($groups as $group) {
            foreach ($group->changes as $change) {
                if ($change->op === SheetChangeOp::Player && is_string($change->to)) {
                    $playerIds[$change->to] = true;
                }

                if ($change->op === SheetChangeOp::NewParticipant && $change->id !== null) {
                    $newParticipantIds[$change->id] = true;
                }

                if ($change->op === SheetChangeOp::NewTeam && $change->id !== null) {
                    $teamIds[$change->id] = true;
                }

                if ($change->teamId !== null) {
                    $teamIds[$change->teamId] = true;
                }

                foreach ([$change->from, $change->to] as $place) {
                    if ($change->op === SheetChangeOp::Place && is_string($place) && SheetChange::teamOfPlace($place) !== null) {
                        $teamIds[(string) SheetChange::teamOfPlace($place)] = true;
                    }
                }
            }
        }

        $site = $this->siteSnapshotReader->read($competitionId, array_keys($playerIds), $stateVersion);

        $participantIdsOfEvent = array_fill_keys(array_column($site->participants, 'id'), true);

        $run = new SheetPlanRun(
            site: $site,
            registrationManaged: $registrationManaged,
            // Ids of other events: a new row with one of them is refused, never taken over (id_taken); a pair/team of
            // another event is refused, one that does not exist at all was deleted already (deleteTeam: unchanged)
            foreignParticipantIds: $this->existing('competition_participant', array_keys(array_diff_key($newParticipantIds, $participantIdsOfEvent))),
            foreignTeamIds: $this->existing('competition_team', array_keys(array_diff_key($teamIds, $site->teams))),
        );

        return $run->run($groups);
    }

    /**
     * @param list<string> $ids
     * @return array<string, true>
     */
    private function existing(string $table, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<string> $found */
        $found = $this->database->fetchFirstColumn(
            // The table name is one of two constants above, never input
            "SELECT id FROM {$table} WHERE id IN (:ids)",
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_fill_keys(array_map('strtolower', $found), true);
    }
}
