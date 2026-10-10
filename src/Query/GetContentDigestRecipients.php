<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\ContentDigestLog;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\ContentDigestKind;
use SpeedPuzzling\Web\Value\DigestPeriod;

/**
 * Who gets the activity digest and who the no-activity e-mail (docs/features/content-digest/README.md §"Who gets
 * what"). Both need: an account e-mail, the digest switched to the frequency whose period this is, and no e-mail yet
 * about this period (a `deferred` one may be tried again). One player per address, most engaged first (the latest
 * day they were on the site, then the oldest account), so the morning goes to the addresses that read us.
 *
 * Activity in a period = at least one result that counts (not awaiting verification) *solved* in it - by the
 * player, or a pair/team result the player is a registered member of. The solve moment is the one XP, streaks and
 * the profile calendar use (COALESCE(finished_at, tracked_at)), so the e-mail's puzzles and its XP agree. Achievements
 * and XP come only from results, so they are not activity of their own; workstream F's karma is the one other
 * activity planned (see activitySql()).
 *
 * No-activity e-mail = nothing logged at all since the period started (not even a back-dated or a not yet verified
 * result, so somebody who logged on Monday morning is never told we missed them), the account existed before the
 * period ended, and never twice in a row: no earlier no-activity e-mail after the last thing the player logged.
 */
readonly final class GetContentDigestRecipients
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<string> player ids, most engaged first
     */
    public function of(ContentDigestKind $kind, ContentDigestFrequency $frequency, DigestPeriod $period): array
    {
        $sql = $this->sql($kind, onePlayer: false);

        /** @var list<string> $ids */
        $ids = $this->database
            ->executeQuery($sql, $this->usedIn($sql, $this->parameters($frequency, $period)))
            ->fetchFirstColumn();

        return $ids;
    }

    /**
     * The re-check right before sending: hours pass between planning and sending, settings and results change.
     */
    public function isRecipient(string $playerId, ContentDigestKind $kind, ContentDigestFrequency $frequency, DigestPeriod $period): bool
    {
        $sql = $this->sql($kind, onePlayer: true);
        $parameters = $this->parameters($frequency, $period);
        $parameters['playerId'] = $playerId;

        return $this->database->executeQuery($sql, $this->usedIn($sql, $parameters))->fetchOne() !== false;
    }

    private function sql(ContentDigestKind $kind, bool $onePlayer): string
    {
        $kindCondition = match ($kind) {
            ContentDigestKind::Activity => $this->activitySql('p'),
            ContentDigestKind::NoActivity => <<<SQL
p.registered_at < CAST(:periodEnd AS TIMESTAMP)
      AND (last_logged.at IS NULL OR last_logged.at < CAST(:periodStart AS TIMESTAMP))
      AND NOT EXISTS (
        SELECT 1 FROM content_digest_log quiet
        WHERE quiet.player_id = p.id
          AND quiet.had_activity = false
          AND quiet.status IN (:sentStatus, :failedStatus)
          AND (last_logged.at IS NULL OR quiet.sent_at > last_logged.at AT TIME ZONE 'UTC')
      )
SQL,
        };

        // The last thing the player logged, solo or in a pair/team - any result, a not yet verified one too: it is
        // what "active again" means for the never-twice rule
        $lastLoggedJoin = $kind === ContentDigestKind::NoActivity ? <<<SQL
    LEFT JOIN LATERAL (
        SELECT GREATEST(
            (SELECT MAX(own.tracked_at) FROM puzzle_solving_time own WHERE own.player_id = p.id),
            (SELECT MAX(grp.tracked_at) FROM puzzling_team_member ptm INNER JOIN puzzle_solving_time grp ON grp.puzzling_team_id = ptm.team_id WHERE ptm.player_id = p.id)
        ) AS at
    ) last_logged ON true
SQL : '';

        $onePlayerCondition = $onePlayer ? 'AND p.id = :playerId' : '';

        return <<<SQL
SELECT id FROM (
    SELECT DISTINCT ON (LOWER(TRIM(ua.email))) p.id, p.registered_at, last_seen.day AS last_seen_day
    FROM player p
    INNER JOIN user_account ua ON ua.user_id = p.user_id
    LEFT JOIN LATERAL (SELECT MAX(pad.day) AS day FROM player_activity_day pad WHERE pad.player_id = p.id) last_seen ON true
{$lastLoggedJoin}
    WHERE TRIM(ua.email) != ''
      {$onePlayerCondition}
      AND p.content_digest_frequency = :frequency
      AND NOT EXISTS (
        SELECT 1 FROM content_digest_log done
        WHERE done.player_id = p.id
          AND done.period_key = :periodKey
          AND done.status != :deferredStatus
      )
      AND {$kindCondition}
    ORDER BY LOWER(TRIM(ua.email)), p.registered_at ASC
) eligible
ORDER BY last_seen_day DESC NULLS LAST, registered_at ASC, id
SQL;
    }

    /**
     * At least one result that counts, solved in the period, solo or as a registered pair/team member. The
     * `tracked_at >= periodStart` bound is implied (a result is logged after it is solved) and lets the planner use
     * the tracked_at index. Workstream F (karma) adds "OR karma gained in the period" here once karma is derived.
     */
    private function activitySql(string $playerAlias): string
    {
        return <<<SQL
(
        EXISTS (
            SELECT 1 FROM puzzle_solving_time own
            WHERE own.player_id = {$playerAlias}.id
              AND own.suspicious = false
              AND own.tracked_at >= CAST(:periodStart AS TIMESTAMP)
              AND COALESCE(own.finished_at, own.tracked_at) >= CAST(:periodStart AS TIMESTAMP)
              AND COALESCE(own.finished_at, own.tracked_at) < CAST(:periodEnd AS TIMESTAMP)
        )
        OR EXISTS (
            SELECT 1 FROM puzzling_team_member ptm
            INNER JOIN puzzle_solving_time grp ON grp.puzzling_team_id = ptm.team_id
            WHERE ptm.player_id = {$playerAlias}.id
              AND grp.suspicious = false
              AND grp.tracked_at >= CAST(:periodStart AS TIMESTAMP)
              AND COALESCE(grp.finished_at, grp.tracked_at) >= CAST(:periodStart AS TIMESTAMP)
              AND COALESCE(grp.finished_at, grp.tracked_at) < CAST(:periodEnd AS TIMESTAMP)
        )
      )
SQL;
    }

    /**
     * @return array<string, string>
     */
    private function parameters(ContentDigestFrequency $frequency, DigestPeriod $period): array
    {
        return [
            'frequency' => $frequency->value,
            'periodKey' => $period->key,
            'periodStart' => $period->startUtc(),
            'periodEnd' => $period->endUtc(),
            'sentStatus' => ContentDigestLog::STATUS_SENT,
            'failedStatus' => ContentDigestLog::STATUS_FAILED_PERMANENT,
            'deferredStatus' => ContentDigestLog::STATUS_DEFERRED,
        ];
    }

    /**
     * @param array<string, string> $parameters
     * @return array<string, string>
     */
    private function usedIn(string $sql, array $parameters): array
    {
        return array_filter(
            $parameters,
            static fn (string $name): bool => preg_match('/:' . $name . '\b/', $sql) === 1,
            ARRAY_FILTER_USE_KEY,
        );
    }
}
