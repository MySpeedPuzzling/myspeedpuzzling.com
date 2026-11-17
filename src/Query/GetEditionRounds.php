<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\EditionRoundPuzzle;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundBadgeColor;
use SpeedPuzzling\Web\Value\RoundTimezone;

readonly final class GetEditionRounds
{
    private RoundPuzzlesVisibleNow $roundPuzzlesVisibleNow;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
        // Built here rather than injected: callers (and the secret puzzle tests) construct this query with the
        // connection and their clock only
        $this->roundPuzzlesVisibleNow = new RoundPuzzlesVisibleNow($database);
    }

    /**
     * @return array<EditionRoundDetail>
     */
    public function forCompetition(string $competitionId): array
    {
        $roundsQuery = <<<SQL
SELECT
    cr.id,
    cr.name,
    cr.minutes_limit,
    cr.starts_at,
    cr.start_time_known,
    cr.category,
    cr.badge_background_color,
    cr.badge_text_color,
    cr.slug,
    cr.results_link,
    cr.timezone,
    cr.results_published_at IS NOT NULL AS results_published,
    c.location_country_code,
    tz_cs.location_country_code AS series_country_code,
    -- The round's online timing has live video on - its watch page (docs/features/online-competitions/), in this
    -- statement so the pages pay nothing for it
    COALESCE(o_r.video_enabled, false) AS online_video
FROM competition_round cr
INNER JOIN competition c ON c.id = cr.competition_id
LEFT JOIN competition_series tz_cs ON tz_cs.id = c.series_id
LEFT JOIN online_round o_r ON o_r.round_id = cr.id
WHERE cr.competition_id = :competitionId
-- The position decides a round's automatic colour (RoundBadgeColor) - the same order everywhere, ties by id
ORDER BY cr.starts_at, cr.id
SQL;

        $rounds = $this->database
            ->executeQuery($roundsQuery, ['competitionId' => $competitionId])
            ->fetchAllAssociative();

        if ($rounds === []) {
            return [];
        }

        /** @var list<string> $roundIds */
        $roundIds = array_column($rounds, 'id');

        // The one rule of what of a round's puzzles anybody may see now (secret puzzle reveal, the puzzle's own
        // hide_until / hide_image_until embargo) - shared with every competition toolkit surface
        /** @var array<string, array<EditionRoundPuzzle>> $puzzlesByRound */
        $puzzlesByRound = [];
        foreach ($this->roundPuzzlesVisibleNow->forRounds($roundIds, $this->clock->now()) as $roundId => $visible) {
            foreach ($visible->puzzles as $puzzle) {
                $puzzlesByRound[$roundId][] = new EditionRoundPuzzle(
                    puzzleId: $puzzle->puzzleId,
                    puzzleName: $puzzle->puzzleName,
                    piecesCount: $puzzle->piecesCount,
                    puzzleImage: $puzzle->puzzleImage,
                    puzzleImageRatio: $puzzle->puzzleImageRatio,
                    manufacturerName: $puzzle->manufacturerName,
                    hidden: false,
                    imageHidden: $puzzle->imageHidden,
                );
            }
        }

        // Rounds come ordered by start, so the index is the round's position in the schedule
        return array_map(static function (array $row, int $schedulePosition) use ($puzzlesByRound): EditionRoundDetail {
            /** @var array{id: string, name: string, minutes_limit: null|int|string, starts_at: string, start_time_known: bool, category: string, badge_background_color: null|string, badge_text_color: null|string, slug: null|string, results_link: null|string, timezone: null|string, results_published: bool, location_country_code: null|string, series_country_code: null|string, online_video: bool} $row */

            $color = RoundBadgeColor::background($row['badge_background_color'], $schedulePosition);

            return new EditionRoundDetail(
                id: $row['id'],
                name: $row['name'],
                startsAt: new DateTimeImmutable($row['starts_at']),
                minutesLimit: $row['minutes_limit'] !== null ? (int) $row['minutes_limit'] : null,
                category: RoundCategory::from($row['category']),
                badgeBackgroundColor: $row['badge_background_color'],
                badgeTextColor: $row['badge_text_color'],
                puzzles: $puzzlesByRound[$row['id']] ?? [],
                color: $color,
                textColor: RoundBadgeColor::text($color),
                slug: $row['slug'],
                resultsLink: $row['results_link'],
                timezone: RoundTimezone::resolve($row['timezone'], $row['location_country_code'], $row['series_country_code']),
                timezoneAssumed: RoundTimezone::isAssumed($row['timezone'], $row['location_country_code'], $row['series_country_code']),
                resultsPublished: $row['results_published'],
                onlineVideo: $row['online_video'],
                startTimeKnown: $row['start_time_known'],
            );
        }, $rounds, array_keys($rounds));
    }
}
