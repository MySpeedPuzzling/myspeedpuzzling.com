<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ResultReviewCandidate;
use SpeedPuzzling\Web\Value\DuplicateCaseStatus;
use SpeedPuzzling\Web\Value\DuplicateTier;
use SpeedPuzzling\Web\Value\ResultReviewContactStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeNoticeVia;

/**
 * Everybody the daily planning of "Your results" e-mails has to consider (docs/features/duplicate-results.md,
 * "Contact rules"): open cases with both copies still there and automatic removals not undone - neither part of
 * a planned or sent e-mail yet - and verification notices (docs/features/suspicious-time-review.md, "Where they see
 * it"): a mark still in force that no e-mail carried and the player has not reacted to yet - only notices of the
 * notice run, the marks e-mailed by hand (`manual_email`) are never told again -, and a moderator's answer no e-mail
 * carried (to any notice: the player asked for it). Only results the person is still in
 * (GetPlayerSuspiciousTimes::sqlStillInTime()).
 *
 * Only players who can get the e-mail at all: the switch on, an e-mail address, and no e-mail waiting to be sent
 * (one planned at a time - that keeps the planning idempotent and a case in one e-mail only).
 */
readonly final class GetResultReviewEmailCandidates
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<ResultReviewCandidate>
     */
    public function all(): array
    {
        $stillInTime = GetPlayerSuspiciousTimes::sqlStillInTime('notice', 't');
        $query = <<<SQL
SELECT c.player_id, CAST(c.id AS text) AS id, CASE c.tier WHEN :possibleTier THEN 'possible' ELSE 'strong' END AS part
FROM result_duplicate_case c
WHERE c.status = :open
    AND EXISTS (SELECT 1 FROM puzzle_solving_time a WHERE a.id = c.time_a_id)
    AND EXISTS (SELECT 1 FROM puzzle_solving_time b WHERE b.id = c.time_b_id)
    AND NOT EXISTS (
        SELECT 1
        FROM result_review_contact contact
        WHERE contact.player_id = c.player_id
            AND contact.status IN (:told)
            AND CAST(contact.case_ids AS jsonb) @> jsonb_build_array(CAST(c.id AS text))
    )
UNION ALL
SELECT removal.player_id, CAST(removal.id AS text), 'removals'
FROM result_auto_removal removal
WHERE removal.reported_at IS NULL
    AND removal.undone_at IS NULL
UNION ALL
SELECT notice.player_id, CAST(notice.id AS text), 'notices'
FROM suspicious_time_notice notice
INNER JOIN suspicious_time_case suspicion ON suspicion.id = notice.case_id
INNER JOIN puzzle_solving_time t ON t.id = suspicion.time_id
WHERE {$stillInTime}
    AND (
        -- The mark is still in force (this very mark, the time still flagged), not e-mailed, not reacted to on the
        -- site - told by the notice run only: the marks e-mailed by hand are never told again
        (
            notice.via = :run
            AND notice.contact_id IS NULL
            AND notice.response IS NULL
            AND notice.answered_at IS NULL
            AND suspicion.status = :marked
            AND notice.marked_at = suspicion.marked_at
            AND t.suspicious = true
        )
        -- A moderator's answer to the player's reply or fix, not e-mailed - however the mark was told
        OR (notice.answered_at IS NOT NULL AND notice.answer_contact_id IS NULL)
    )
ORDER BY 1, 2
SQL;

        /** @var list<array{player_id: string, id: string, part: 'strong'|'possible'|'removals'|'notices'}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'open' => DuplicateCaseStatus::Open->value,
            'told' => [ResultReviewContactStatus::Planned->value, ResultReviewContactStatus::Sent->value],
            'possibleTier' => DuplicateTier::Possible->value,
            'run' => SuspiciousTimeNoticeVia::Run->value,
            'marked' => SuspiciousTimeCaseStatus::Marked->value,
        ], [
            'told' => ArrayParameterType::STRING,
        ]);

        /** @var array<string, array{strong: list<string>, possible: list<string>, removals: list<string>, notices: list<string>}> $news */
        $news = [];

        foreach ($rows as $row) {
            $news[$row['player_id']] ??= ['strong' => [], 'possible' => [], 'removals' => [], 'notices' => []];
            $news[$row['player_id']][$row['part']][] = $row['id'];
        }

        if ($news === []) {
            return [];
        }

        $query = <<<SQL
SELECT
    player.id AS player_id,
    (SELECT MAX(activity.day) FROM player_activity_day activity WHERE activity.player_id = player.id) AS last_active_on,
    latest.sent_at AS last_sent_at,
    latest.reacted_at AS last_sent_reacted_at,
    (
        SELECT MAX(contact.sent_at)
        FROM result_review_contact contact
        WHERE contact.player_id = player.id
            AND contact.status = :sent
            AND json_array_length(contact.case_ids) > 0
    ) AS last_sent_with_cases_at
FROM player
INNER JOIN user_account ON user_account.user_id = player.user_id
LEFT JOIN LATERAL (
    SELECT contact.sent_at, contact.reacted_at
    FROM result_review_contact contact
    WHERE contact.player_id = player.id
        AND contact.status = :sent
    ORDER BY contact.sent_at DESC
    LIMIT 1
) latest ON TRUE
WHERE player.id IN (:playerIds)
    AND player.result_emails_enabled = true
    AND NOT EXISTS (
        SELECT 1
        FROM result_review_contact contact
        WHERE contact.player_id = player.id
            AND contact.status = :planned
    )
ORDER BY player.id
SQL;

        /** @var list<array{player_id: string, last_active_on: null|string, last_sent_at: null|string, last_sent_reacted_at: null|string, last_sent_with_cases_at: null|string}> $players */
        $players = $this->database->fetchAllAssociative($query, [
            'playerIds' => array_keys($news),
            'sent' => ResultReviewContactStatus::Sent->value,
            'planned' => ResultReviewContactStatus::Planned->value,
        ], [
            'playerIds' => ArrayParameterType::STRING,
        ]);

        return array_map(static fn (array $player): ResultReviewCandidate => new ResultReviewCandidate(
            playerId: $player['player_id'],
            lastActiveOn: $player['last_active_on'] !== null ? new DateTimeImmutable($player['last_active_on']) : null,
            lastSentAt: $player['last_sent_at'] !== null ? new DateTimeImmutable($player['last_sent_at']) : null,
            lastSentReactedAt: $player['last_sent_reacted_at'] !== null ? new DateTimeImmutable($player['last_sent_reacted_at']) : null,
            lastSentWithCasesAt: $player['last_sent_with_cases_at'] !== null ? new DateTimeImmutable($player['last_sent_with_cases_at']) : null,
            strongCaseIds: $news[$player['player_id']]['strong'],
            possibleCaseIds: $news[$player['player_id']]['possible'],
            removalIds: $news[$player['player_id']]['removals'],
            suspiciousNoticeIds: $news[$player['player_id']]['notices'],
        ), $players);
    }
}
