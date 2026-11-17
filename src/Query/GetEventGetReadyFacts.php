<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Results\GetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyRoundFacts;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\QrTagMode;
use SpeedPuzzling\Web\Value\RoundStopwatchStatus;

/**
 * The facts of the "Get ready" checklist on an event's edit page (W2 of the event creation wizard,
 * docs/features/competitions-management/toolkit/W-creation-wizard-research.md §3.10): **two statements** whatever the
 * number of rounds - the event with its counts, then its rounds with theirs. Organiser tooling: the caller checked
 * COMPETITION_EDIT. Entries are counted like GetRoundResultsOverview (solo: participants going; pairs/teams: teams).
 */
readonly final class GetEventGetReadyFacts
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     */
    public function of(string $competitionId, bool $toolkitAvailable): GetReadyFacts
    {
        if (Uuid::isValid($competitionId) === false) {
            throw new CompetitionNotFound();
        }

        $going = CompetitionParticipantGoing::sql('cp');
        $screen = DisplayKind::Screen->value;
        $overlay = DisplayKind::Overlay->value;

        $event = <<<SQL
SELECT
    c.is_draft,
    c.is_online,
    c.date_from,
    c.date_to,
    c.registration_managed,
    c.capacity,
    c.qr_tag_mode,
    (SELECT COUNT(*) FROM competition_participant cp WHERE cp.competition_id = c.id AND {$going}) AS participants_going,
    (SELECT COUNT(*) FROM competition_referee r WHERE r.competition_id = c.id) AS referees_count,
    (SELECT COUNT(*) FROM competition_display d WHERE d.competition_id = c.id AND d.kind = '{$screen}') AS screens_count,
    (SELECT COUNT(*) FROM competition_display d WHERE d.competition_id = c.id AND d.kind = '{$overlay}') AS overlays_count
FROM competition c
WHERE c.id = :competitionId
SQL;

        /** @var false|array{is_draft: bool, is_online: bool, date_from: null|string, date_to: null|string, registration_managed: bool, capacity: null|int, participants_going: int|string, referees_count: int|string, screens_count: int|string, overlays_count: int|string, qr_tag_mode: null|string} $row */
        $row = $this->database->executeQuery($event, ['competitionId' => $competitionId])->fetchAssociative();

        if ($row === false) {
            throw new CompetitionNotFound();
        }

        $rounds = <<<SQL
SELECT
    cr.id,
    cr.name,
    cr.starts_at,
    cr.minutes_limit,
    cr.stopwatch_status,
    cr.stopwatch_started_at,
    cr.table_numbers_off,
    puzzles.puzzles_count,
    puzzles.secret_count,
    entries.total,
    entries.with_table_number
FROM competition_round cr
LEFT JOIN LATERAL (
    SELECT
        COUNT(*) AS puzzles_count,
        COUNT(*) FILTER (WHERE crp.hide_until_round_starts) AS secret_count
    FROM competition_round_puzzle crp
    WHERE crp.round_id = cr.id
) puzzles ON true
LEFT JOIN LATERAL (
    SELECT
        COUNT(*) AS total,
        COUNT(entry.table_number) AS with_table_number
    FROM (
        SELECT cpr.table_number
        FROM competition_participant_round cpr
        INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND {$going}
        WHERE cpr.round_id = cr.id
            AND cr.category = 'solo'
        UNION ALL
        SELECT ct.table_number
        FROM competition_team ct
        WHERE ct.round_id = cr.id
            AND cr.category <> 'solo'
    ) entry
) entries ON true
WHERE cr.competition_id = :competitionId
ORDER BY cr.starts_at, cr.name, cr.id
SQL;

        $now = $this->clock->now();
        $date = static fn (null|string $value): null|DateTimeImmutable => $value !== null ? new DateTimeImmutable($value) : null;
        $roundFacts = [];

        foreach ($this->database->executeQuery($rounds, ['competitionId' => $competitionId])->fetchAllAssociative() as $round) {
            /** @var array{id: string, name: string, starts_at: string, minutes_limit: null|int, stopwatch_status: null|string, stopwatch_started_at: null|string, table_numbers_off: bool, puzzles_count: int|string, secret_count: int|string, total: int|string, with_table_number: int|string} $round */
            $startedAt = $date($round['stopwatch_started_at']);

            $roundFacts[] = new GetReadyRoundFacts(
                roundId: $round['id'],
                name: $round['name'],
                startsAt: new DateTimeImmutable($round['starts_at']),
                minutesLimit: $round['minutes_limit'],
                stopwatchStatus: RoundStopwatchStatus::at($round['stopwatch_status'], $startedAt, $now),
                stopwatchStartedAt: $startedAt,
                tableNumbersOff: $round['table_numbers_off'],
                puzzlesCount: (int) $round['puzzles_count'],
                secretPuzzlesCount: (int) $round['secret_count'],
                entriesTotal: (int) $round['total'],
                entriesWithTableNumber: (int) $round['with_table_number'],
            );
        }

        return new GetReadyFacts(
            competitionId: strtolower($competitionId),
            isDraft: $row['is_draft'],
            isOnline: $row['is_online'],
            dateFrom: $date($row['date_from']),
            dateTo: $date($row['date_to']),
            registrationManaged: $row['registration_managed'],
            capacity: $row['capacity'],
            participantsGoing: (int) $row['participants_going'],
            refereesCount: (int) $row['referees_count'],
            screenDisplaysCount: (int) $row['screens_count'],
            overlayDisplaysCount: (int) $row['overlays_count'],
            qrTagMode: $row['qr_tag_mode'] !== null ? QrTagMode::tryFrom($row['qr_tag_mode']) : null,
            rounds: $roundFacts,
            toolkitAvailable: $toolkitAvailable,
            now: $now,
        );
    }
}
