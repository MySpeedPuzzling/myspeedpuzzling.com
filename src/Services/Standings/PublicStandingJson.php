<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Standings;

use SpeedPuzzling\Web\Results\PublicStandingSnapshot;

/**
 * The public JSON of a published standing (competition toolkit v2 S1, displays-standings.md §3.1,
 * `public_standing_state`) - an explicit whitelist over the guest snapshot: never a row's `key`, never a player id,
 * participant, line-up or entry id; a #code only for a public profile. Statuses are passed through as the calculator
 * names them (`finished`, `unfinished`, `did_not_start`, ... - a status added later reaches the JSON unchanged).
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
            ], $data['rows']),
            'generatedAtMs' => $data['generatedAtMs'],
        ];
    }
}
