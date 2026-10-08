<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport;

use SpeedPuzzling\Web\Query\GetParticipantImportStateVersion;
use SpeedPuzzling\Web\Results\ParticipantImportPlan;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\PlanBuilder;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\SiteSnapshot;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRound;
use SpeedPuzzling\Web\Value\ParticipantImportRows;

/**
 * What confirming an import would do - read only (DBAL reads through SiteSnapshotReader, no entity is touched, no id
 * generated), deterministic: the same file on the same event plans the same, with the same fingerprint. The rules live
 * in PlanBuilder and ParticipantRules (shared with the participants sheet).
 *
 * docs/features/competitions-management/participant-import-preview.md
 */
readonly final class ParticipantImportPlanner
{
    public function __construct(
        private GetParticipantImportStateVersion $getStateVersion,
        private SiteSnapshotReader $siteSnapshotReader,
    ) {
    }

    /**
     * @return list<ParticipantImportRound> ordered by start, name, id
     */
    public function rounds(string $competitionId): array
    {
        return $this->siteSnapshotReader->rounds($competitionId);
    }

    public function plan(string $competitionId, ParticipantImportRows $rows, ParticipantImportMode $mode): ParticipantImportPlan
    {
        $site = $this->snapshot($competitionId, $rows);

        $built = (new PlanBuilder($site, $rows, $mode))->build();

        // "Update only" shows what full sync would remove too (D7) - the same rows planned the other way
        $removals = $mode === ParticipantImportMode::Sync
            ? $built->removals
            : (new PlanBuilder($site, $rows, ParticipantImportMode::Sync))->build()->removals;

        $existingPlayers = array_keys($site->existingPlayers);
        sort($existingPlayers);

        $fingerprint = hash('sha256', json_encode([
            $rows->hash(),
            $mode->value,
            $site->stateVersion,
            // Results are not in the state version; what the plan keeps because of them is (D8, D11)
            $built->resultsGuard,
            // The file's msp_player_ids that exist - a player deleted meanwhile is not connected
            $existingPlayers,
        ], JSON_THROW_ON_ERROR));

        return new ParticipantImportPlan(
            mode: $mode,
            rows: $built->rows,
            warnings: $built->warnings,
            errors: [],
            removals: $removals,
            operations: $built->operations,
            fingerprint: $fingerprint,
            syncBlockers: $built->syncBlockers,
            activeParticipantsBefore: $built->activeParticipantsBefore,
        );
    }

    private function snapshot(string $competitionId, ParticipantImportRows $rows): SiteSnapshot
    {
        $playerIds = [];
        foreach ($rows->rows as $row) {
            if ($row->playerId !== null) {
                $playerIds[] = $row->playerId;
            }
        }

        // First, before the data: a change committed between the reads then makes the plan stale on confirm,
        // instead of a plan nobody saw carrying the newer version
        $stateVersion = $this->getStateVersion->ofCompetition($competitionId);

        return $this->siteSnapshotReader->read($competitionId, $playerIds, $stateVersion);
    }
}
