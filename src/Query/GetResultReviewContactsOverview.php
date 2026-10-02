<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\DuplicateCaseStatus;
use SpeedPuzzling\Web\Value\ResultReviewContactSkipReason;
use SpeedPuzzling\Web\Value\ResultReviewContactStatus;
use SpeedPuzzling\Web\Value\ResultReviewContactType;

/**
 * The "Your results" e-mails in numbers, for /admin/duplicate-results (docs/features/duplicate-results.md, "Admin
 * overview"): planned → sent → review page visited → reacted → all the player's cases resolved, per type and per
 * wave (the e-mails sent on one Europe/Prague day). Reads only result_review_contact (+ small lookups).
 *
 * Times are stored in UTC (the app's timezone), so a wave's day is converted explicitly.
 *
 * @phpstan-type FunnelRow array{group_value: string, planned: int|string, sent: int|string, skipped: int|string, visited: int|string, reacted: int|string, all_resolved: int|string, median_seconds_to_react: null|float|string, first: int|string, weekly: int|string}
 */
readonly final class GetResultReviewContactsOverview
{
    public const int IGNORED_AFTER_DAYS = 7;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string, array{planned: int, sent: int, skipped: int, visited: int, reacted: int, all_resolved: int, median_hours_to_react: null|float}> by type
     */
    public function funnelByType(): array
    {
        $funnel = [];

        foreach (ResultReviewContactType::cases() as $type) {
            $funnel[$type->value] = ['planned' => 0, 'sent' => 0, 'skipped' => 0, 'visited' => 0, 'reacted' => 0, 'all_resolved' => 0, 'median_hours_to_react' => null];
        }

        foreach ($this->funnelRows('contact.type', '') as $row) {
            $funnel[$row['group_value']] = self::funnelOf($row);
        }

        return $funnel;
    }

    /**
     * @return list<array{day: string, first: int, weekly: int, planned: int, sent: int, skipped: int, visited: int, reacted: int, all_resolved: int, median_hours_to_react: null|float}> newest first
     */
    public function waves(): array
    {
        $day = "CAST(timezone('Europe/Prague', timezone('UTC', contact.sent_at)) AS date)";

        return array_map(static fn (array $row): array => [
            'day' => $row['group_value'],
            'first' => (int) $row['first'],
            'weekly' => (int) $row['weekly'],
            ...self::funnelOf($row),
        ], $this->funnelRows($day, 'WHERE contact.status = :sent'));
    }

    /**
     * @return array{unsubscribed: int, ignoring: int, skipped_switched_off: int, skipped_no_email: int, skipped_nothing_left: int}
     */
    public function totals(DateTimeImmutable $now): array
    {
        $query = <<<SQL
SELECT
    (SELECT COUNT(*) FROM player WHERE result_emails_enabled = false) AS unsubscribed,
    (
        SELECT COUNT(*)
        FROM (
            SELECT DISTINCT ON (contact.player_id) contact.reacted_at, contact.sent_at
            FROM result_review_contact contact
            WHERE contact.status = :sent
            ORDER BY contact.player_id, contact.sent_at DESC
        ) latest
        WHERE latest.reacted_at IS NULL AND latest.sent_at < :ignoredBefore
    ) AS ignoring,
    COUNT(*) FILTER (WHERE skipped_reason = :switchedOff) AS skipped_switched_off,
    COUNT(*) FILTER (WHERE skipped_reason = :noEmail) AS skipped_no_email,
    COUNT(*) FILTER (WHERE skipped_reason = :nothingLeft) AS skipped_nothing_left
FROM result_review_contact
SQL;

        /** @var array{unsubscribed: int|string, ignoring: int|string, skipped_switched_off: int|string, skipped_no_email: int|string, skipped_nothing_left: int|string} $row */
        $row = $this->database->fetchAssociative($query, [
            'sent' => ResultReviewContactStatus::Sent->value,
            'ignoredBefore' => $now->modify('-' . self::IGNORED_AFTER_DAYS . ' days')->format('Y-m-d H:i:s'),
            'switchedOff' => ResultReviewContactSkipReason::SwitchedOff->value,
            'noEmail' => ResultReviewContactSkipReason::NoEmail->value,
            'nothingLeft' => ResultReviewContactSkipReason::NothingLeft->value,
        ]);

        return array_map(static fn (int|string $value): int => (int) $value, $row);
    }

    /**
     * What the mail log knows about these e-mails - bounces arrive there (email_audit_log, kept 90 days).
     *
     * @return array{logged: int, failed: int, bounced: int}
     */
    public function delivery(): array
    {
        /** @var array{logged: int|string, failed: int|string, bounced: int|string} $row */
        $row = $this->database->fetchAssociative(
            "SELECT COUNT(*) AS logged, COUNT(*) FILTER (WHERE status = 'failed') AS failed, COUNT(*) FILTER (WHERE bounced_at IS NOT NULL) AS bounced FROM email_audit_log WHERE email_type = 'result_review'",
        );

        return array_map(static fn (int|string $value): int => (int) $value, $row);
    }

    /**
     * @return list<FunnelRow>
     */
    private function funnelRows(string $groupBy, string $where): array
    {
        $query = <<<SQL
SELECT
    CAST({$groupBy} AS text) AS group_value,
    COUNT(*) FILTER (WHERE contact.status = :planned) AS planned,
    COUNT(*) FILTER (WHERE contact.status = :sent) AS sent,
    COUNT(*) FILTER (WHERE contact.status = :skipped) AS skipped,
    COUNT(*) FILTER (WHERE contact.page_visited_at IS NOT NULL) AS visited,
    COUNT(*) FILTER (WHERE contact.reacted_at IS NOT NULL) AS reacted,
    COUNT(*) FILTER (
        WHERE contact.status = :sent
            AND json_array_length(contact.case_ids) > 0
            AND NOT EXISTS (SELECT 1 FROM result_duplicate_case c WHERE c.player_id = contact.player_id AND c.status = :open)
    ) AS all_resolved,
    percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM contact.reacted_at - contact.sent_at)) FILTER (WHERE contact.reacted_at IS NOT NULL) AS median_seconds_to_react,
    COUNT(*) FILTER (WHERE contact.type = :first) AS first,
    COUNT(*) FILTER (WHERE contact.type = :weekly) AS weekly
FROM result_review_contact contact
{$where}
GROUP BY 1
ORDER BY 1 DESC
SQL;

        /** @var list<FunnelRow> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'planned' => ResultReviewContactStatus::Planned->value,
            'sent' => ResultReviewContactStatus::Sent->value,
            'skipped' => ResultReviewContactStatus::Skipped->value,
            'open' => DuplicateCaseStatus::Open->value,
            'first' => ResultReviewContactType::First->value,
            'weekly' => ResultReviewContactType::Weekly->value,
        ]);

        return $rows;
    }

    /**
     * @param FunnelRow $row
     * @return array{planned: int, sent: int, skipped: int, visited: int, reacted: int, all_resolved: int, median_hours_to_react: null|float}
     */
    private static function funnelOf(array $row): array
    {
        return [
            'planned' => (int) $row['planned'],
            'sent' => (int) $row['sent'],
            'skipped' => (int) $row['skipped'],
            'visited' => (int) $row['visited'],
            'reacted' => (int) $row['reacted'],
            'all_resolved' => (int) $row['all_resolved'],
            'median_hours_to_react' => $row['median_seconds_to_react'] !== null ? round((float) $row['median_seconds_to_react'] / 3600, 1) : null,
        ];
    }
}
