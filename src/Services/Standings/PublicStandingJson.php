<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Standings;

use SpeedPuzzling\Web\Results\PublicStandingSnapshot;

/**
 * The public JSON of a published standing (competition toolkit v2 S1, displays-standings.md §3.1,
 * `public_standing_state`) - an explicit whitelist over the guest snapshot: never a row's `key`, never a player id,
 * participant, line-up or entry id; a #code only for a public profile. Statuses are passed through as the calculator
 * names them (`finished`, `unfinished`, `did_not_start`, ... - a status added later reaches the JSON unchanged).
 *
 * A `stages` standing (competition toolkit v2 S5) adds `standing.numberedAcrossStages` and per row `stage` (1 = the
 * first), `stageName` (its rounds' names), `stageRoundId` + `stageRank` (its round there and its place in it - null
 * for a row "qualified for" a stage not classified yet) and `qualifiedForStage`; `rank` is the number across the stages
 * when numbered, else null. A `sum` standing's JSON has none of them. A standing limited to one division (real events
 * L2a) adds `standing.division` {name, shortName}; every other standing's JSON is unchanged.
 */
final class PublicStandingJson
{
    /**
     * @param \Closure(null|string): (null|string) $roundUrl the round's public results page (by its slug), null without one
     * @return array<string, mixed>
     */
    public static function of(PublicStandingSnapshot $snapshot, null|string $standingUrl, \Closure $roundUrl): array
    {
        $data = $snapshot->toArray();

        return [
            'standing' => [
                'id' => $data['standingId'],
                'name' => $data['name'],
                'category' => $data['category'],
                'mode' => $data['mode'],
                'published' => $data['published'],
                'provisional' => $data['provisional'],
                'roundsCounted' => $data['roundsCounted'],
                'roundsTotal' => $data['roundsTotal'],
                'rules' => [
                    'penaltySecondsPerPiece' => $data['rules']['penaltySecondsPerPiece'],
                    'order' => $data['rules']['order'],
                    'missing' => $data['rules']['missing'],
                    'penaliseFinishedMissing' => $data['rules']['penaliseFinishedMissing'],
                ],
                'url' => $standingUrl,
                ...(isset($data['numberedAcrossStages']) ? ['numberedAcrossStages' => $data['numberedAcrossStages']] : []),
                // Real events L2a: only a standing limited to one division - its name and short label, never its id
                ...(isset($data['division']) ? ['division' => ['name' => $data['division']['name'], 'shortName' => $data['division']['shortName']]] : []),
            ],
            'rounds' => array_map(static fn (array $round): array => [
                'id' => $round['id'],
                'name' => $round['name'],
                'slug' => $round['slug'],
                'minutesLimit' => $round['minutesLimit'],
                'counted' => $round['counted'],
                'url' => $round['published'] ? $roundUrl($round['slug']) : null,
            ], $data['rounds']),
            'rows' => array_map(static fn (array $row): array => [
                'rank' => $row['rank'],
                'complete' => $row['complete'],
                'name' => $row['name'],
                'countries' => $row['countries'],
                'entrants' => array_map(static fn (array $entrant): array => [
                    'name' => $entrant['name'],
                    'country' => $entrant['country'],
                    'playerCode' => $entrant['playerCode'],
                ], $row['entrants']),
                'totalSeconds' => $row['totalSeconds'],
                'totalTimeSeconds' => $row['totalTimeSeconds'],
                'totalMissingPieces' => $row['totalMissingPieces'],
                'totalPenaltySeconds' => $row['totalPenaltySeconds'],
                'roundsScored' => $row['roundsScored'],
                'roundsFinished' => $row['roundsFinished'],
                'perRound' => array_map(static fn (array $score): array => [
                    'roundId' => $score['roundId'],
                    'status' => $score['status'],
                    'scoreSeconds' => $score['scoreSeconds'],
                    'timeSeconds' => $score['timeSeconds'],
                    'missingPieces' => $score['missingPieces'],
                    'penaltySeconds' => $score['penaltySeconds'],
                    'rank' => $score['rank'],
                    'result' => $score['result'] !== null ? [
                        'seconds' => $score['result']['seconds'],
                        'piecesPlaced' => $score['result']['piecesPlaced'],
                    ] : null,
                ], $row['perRound']),
                ...(isset($row['stage']) ? [
                    'stage' => $row['stage'],
                    'stageName' => $row['stageName'] ?? null,
                    'stageRoundId' => $row['stageRoundId'] ?? null,
                    'stageRank' => $row['stageRank'] ?? null,
                    'qualifiedForStage' => $row['qualifiedForStage'] ?? false,
                ] : []),
            ], $data['rows']),
            'generatedAtMs' => $data['generatedAtMs'],
        ];
    }
}
