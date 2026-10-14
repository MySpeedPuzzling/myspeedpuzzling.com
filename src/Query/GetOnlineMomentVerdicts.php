<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineMomentVerdict;

/**
 * The reviewers' gap marks on finish moments (online_review_moment_verdict, docs/features/online-competitions/README.md
 * §5.9): the event ids of the moments - raises of the hands and Finished taps - whose photo a reviewer marked as showing
 * a gap. One statement each - for one participant's evidence (the review's detail), or for a whole round (the accuracy
 * report, which reads every mark: it has the candidates of a `shadow` round too). Every id in lower case, by moment.
 */
readonly final class GetOnlineMomentVerdicts
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param bool $candidates false: the taps' marks only - the review of a `shadow` round never shows a raise of the
     *                         hands (README §5.7), so it never shows a mark on one either
     * @return list<string> the event ids of the online entry's moments marked as a gap
     */
    public function gapsOfEntry(string $onlineEntryId, bool $candidates = true): array
    {
        if (Uuid::isValid($onlineEntryId) === false) {
            return [];
        }

        $types = $candidates
            ? [OnlineEventType::FinishButton->value, OnlineEventType::FinishCandidate->value]
            : [OnlineEventType::FinishButton->value];

        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn(
            <<<SQL
SELECT v.event_id::text
FROM online_review_moment_verdict v
INNER JOIN online_entry_event e ON e.id = v.event_id
WHERE v.online_entry_id = :onlineEntryId
    AND v.verdict = :gap
    AND e.type IN (:types)
ORDER BY e.client_ms, e.id
SQL,
            ['onlineEntryId' => strtolower($onlineEntryId), 'gap' => OnlineMomentVerdict::Gap->value, 'types' => $types],
            ['types' => ArrayParameterType::STRING],
        );

        return $ids;
    }

    /**
     * @return array<string, list<string>> online entry id => the event ids of its moments marked as a gap
     */
    public function gapsOfRound(string $roundId): array
    {
        if (Uuid::isValid($roundId) === false) {
            return [];
        }

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT v.online_entry_id::text AS online_entry_id, v.event_id::text AS event_id
FROM online_review_moment_verdict v
INNER JOIN online_entry oe ON oe.id = v.online_entry_id
INNER JOIN competition_participant_round cpr ON cpr.id = oe.entry_id
INNER JOIN online_entry_event e ON e.id = v.event_id
WHERE cpr.round_id = :roundId
    AND v.verdict = :gap
ORDER BY v.online_entry_id, e.client_ms, e.id
SQL,
            ['roundId' => strtolower($roundId), 'gap' => OnlineMomentVerdict::Gap->value],
        );

        $gaps = [];

        foreach ($rows as $row) {
            if (is_string($row['online_entry_id']) && is_string($row['event_id'])) {
                $gaps[$row['online_entry_id']][] = $row['event_id'];
            }
        }

        return $gaps;
    }
}
