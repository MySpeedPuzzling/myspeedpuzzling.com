<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\ContentDigestLog;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\DigestPeriod;

/**
 * Who gets the activity digest and who the "We miss you" e-mail (docs/features/content-digest/README.md §"Who gets
 * what"). Both need an account e-mail and the activity-digest switch on (its frequency); one player per address, most
 * engaged first (the latest day they were on the site, then the oldest account), so the morning goes to the addresses
 * that read us.
 *
 * Activity digest: activity in the period = at least one result that counts (not awaiting verification) *solved* in
 * it - by the player, or a pair/team result the player is a registered member of - and no e-mail about the period yet
 * (a `deferred` one may be tried again). The solve moment is the one XP, streaks and the profile calendar use
 * (COALESCE(finished_at, tracked_at)), so the e-mail's puzzles and its XP agree. Achievements and XP come only from
 * results, so they are not activity of their own; workstream F's karma is the one other activity planned (see
 * activitySql()). Nobody without activity gets a digest - no empty or "quiet" variant.
 *
 * "We miss you": the last result the player logged (any, solo or in a pair/team, a back-dated or not yet verified one
 * too) is at least MISS_YOU_AFTER_DAYS old - players who never logged a result are not missed - and they were not told
 * since: no "We miss you" e-mail after that last result (once per inactive spell; activity restarts the clock).
 */
readonly final class GetContentDigestRecipients
{
    public const int MISS_YOU_AFTER_DAYS = 28;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<string> player ids, most engaged first
     */
    public function withActivity(ContentDigestFrequency $frequency, DigestPeriod $period): array
    {
        return $this->ids($this->activitySql(onePlayer: false), $this->activityParameters($frequency, $period));
    }

    public function hadActivity(string $playerId, ContentDigestFrequency $frequency, DigestPeriod $period): bool
    {
        $parameters = $this->activityParameters($frequency, $period);
        $parameters['playerId'] = $playerId;

        return $this->database->executeQuery($this->activitySql(onePlayer: true), $parameters)->fetchOne() !== false;
    }

    /**
     * @return list<string> player ids, most recently active first
     */
    public function missed(ContentDigestFrequency $frequency, DateTimeImmutable $now): array
    {
        return $this->ids($this->missYouSql(onePlayer: false), $this->missYouParameters($frequency, $now));
    }

    public function isMissed(string $playerId, ContentDigestFrequency $frequency, DateTimeImmutable $now): bool
    {
        $parameters = $this->missYouParameters($frequency, $now);
        $parameters['playerId'] = $playerId;

        return $this->database->executeQuery($this->missYouSql(onePlayer: true), $parameters)->fetchOne() !== false;
    }

    /**
     * When the player last logged a result (UTC), solo or in a pair/team - what identifies an inactive spell.
     */
    public function lastLoggedAt(string $playerId): null|DateTimeImmutable
    {
        $value = $this->database->fetchOne(
            "SELECT {$this->lastLoggedSql(':playerId')}",
            ['playerId' => $playerId],
        );

        return is_string($value) ? new DateTimeImmutable($value, new DateTimeZone('UTC')) : null;
    }

    /**
     * @param array<string, string> $parameters
     * @return list<string>
     */
    private function ids(string $sql, array $parameters): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->executeQuery($sql, $parameters)->fetchFirstColumn();

        return $ids;
    }

    private function activitySql(bool $onePlayer): string
    {
        $onePlayerCondition = $onePlayer ? 'AND p.id = :playerId' : '';

        // Workstream F (karma) adds "OR karma gained in the period" to the activity condition once karma is derived.
        // The `tracked_at >= periodStart` bounds are implied (a result is logged after it is solved) and let the
        // planner use the tracked_at index.
        return <<<SQL
SELECT id FROM (
    SELECT DISTINCT ON (LOWER(TRIM(ua.email))) p.id, p.registered_at, last_seen.day AS last_seen_day
    FROM player p
    INNER JOIN user_account ua ON ua.user_id = p.user_id
    LEFT JOIN LATERAL (SELECT MAX(pad.day) AS day FROM player_activity_day pad WHERE pad.player_id = p.id) last_seen ON true
    WHERE TRIM(ua.email) != ''
      {$onePlayerCondition}
      AND p.content_digest_frequency = :frequency
      AND NOT EXISTS (
        SELECT 1 FROM content_digest_log done
        WHERE done.player_id = p.id
          AND done.period_key = :periodKey
          AND done.status != :deferredStatus
      )
      AND (
        EXISTS (
            SELECT 1 FROM puzzle_solving_time own
            WHERE own.player_id = p.id
              AND own.suspicious = false
              AND own.tracked_at >= CAST(:periodStart AS TIMESTAMP)
              AND COALESCE(own.finished_at, own.tracked_at) >= CAST(:periodStart AS TIMESTAMP)
              AND COALESCE(own.finished_at, own.tracked_at) < CAST(:periodEnd AS TIMESTAMP)
        )
        OR EXISTS (
            SELECT 1 FROM puzzling_team_member ptm
            INNER JOIN puzzle_solving_time grp ON grp.puzzling_team_id = ptm.team_id
            WHERE ptm.player_id = p.id
              AND grp.suspicious = false
              AND grp.tracked_at >= CAST(:periodStart AS TIMESTAMP)
              AND COALESCE(grp.finished_at, grp.tracked_at) >= CAST(:periodStart AS TIMESTAMP)
              AND COALESCE(grp.finished_at, grp.tracked_at) < CAST(:periodEnd AS TIMESTAMP)
        )
      )
    ORDER BY LOWER(TRIM(ua.email)), p.registered_at ASC
) eligible
ORDER BY last_seen_day DESC NULLS LAST, registered_at ASC, id
SQL;
    }

    private function missYouSql(bool $onePlayer): string
    {
        $onePlayerCondition = $onePlayer ? 'AND p.id = :playerId' : '';

        // Any logged result counts as activity here, a not yet verified (suspicious) one too: it is what "active
        // again" means, and nobody is missed the week after logging something
        return <<<SQL
SELECT id FROM (
    SELECT DISTINCT ON (LOWER(TRIM(ua.email))) p.id, p.registered_at, last_logged.at AS last_logged_at
    FROM player p
    INNER JOIN user_account ua ON ua.user_id = p.user_id
    CROSS JOIN LATERAL (SELECT {$this->lastLoggedSql('p.id')} AS at) last_logged
    WHERE TRIM(ua.email) != ''
      {$onePlayerCondition}
      AND p.content_digest_frequency = :frequency
      AND last_logged.at IS NOT NULL
      AND last_logged.at <= CAST(:missedSince AS TIMESTAMP)
      AND NOT EXISTS (
        SELECT 1 FROM content_digest_log told
        WHERE told.player_id = p.id
          AND told.had_activity = false
          AND told.status IN (:sentStatus, :failedStatus)
          AND told.sent_at > last_logged.at AT TIME ZONE 'UTC'
      )
    ORDER BY LOWER(TRIM(ua.email)), p.registered_at ASC
) eligible
ORDER BY last_logged_at DESC, registered_at ASC, id
SQL;
    }

    /**
     * The last result logged by a player, solo or as a registered pair/team member (suspicious ones too).
     */
    private function lastLoggedSql(string $playerIdExpression): string
    {
        return <<<SQL
GREATEST(
            (SELECT MAX(own.tracked_at) FROM puzzle_solving_time own WHERE own.player_id = {$playerIdExpression}),
            (SELECT MAX(grp.tracked_at) FROM puzzling_team_member ptm INNER JOIN puzzle_solving_time grp ON grp.puzzling_team_id = ptm.team_id WHERE ptm.player_id = {$playerIdExpression})
        )
SQL;
    }

    /**
     * @return array<string, string>
     */
    private function activityParameters(ContentDigestFrequency $frequency, DigestPeriod $period): array
    {
        return [
            'frequency' => $frequency->value,
            'periodKey' => $period->key,
            'periodStart' => $period->startUtc(),
            'periodEnd' => $period->endUtc(),
            'deferredStatus' => ContentDigestLog::STATUS_DEFERRED,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function missYouParameters(ContentDigestFrequency $frequency, DateTimeImmutable $now): array
    {
        return [
            'frequency' => $frequency->value,
            'missedSince' => $now->setTimezone(new DateTimeZone('UTC'))->modify(sprintf('-%d days', self::MISS_YOU_AFTER_DAYS))->format('Y-m-d H:i:s'),
            'sentStatus' => ContentDigestLog::STATUS_SENT,
            'failedStatus' => ContentDigestLog::STATUS_FAILED_PERMANENT,
        ];
    }
}
