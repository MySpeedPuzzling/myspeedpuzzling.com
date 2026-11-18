<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * What the live control page shows of an event's displays (competition toolkit v2 L1/L2, displays-standings.md §5.3):
 * the live message and the recent ones, how many displays the event has and how many shown `message` / `spotlight`
 * elements their designs have (the hint "Add a … element to a display design" when none) - one statement. Organiser
 * tooling only (CompetitionDisplayControlStateController asks COMPETITION_TOOLKIT_MANAGE first).
 */
readonly final class GetDisplayControlState
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{message: null|string, recentMessages: list<string>, displays: int, elements: array{message: int, spotlight: int}}
     */
    public function ofCompetition(string $competitionId): array
    {
        // Normalised designs only (DisplayDesignSanitizer): `hidden` is a boolean, `when.phases` a list
        $shown = static fn (string $type): string => <<<SQL
(
    SELECT COUNT(*)
    FROM competition_display d
    CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(d.design -> 'elements') = 'array' THEN d.design -> 'elements' ELSE '[]'::jsonb END) e
    WHERE d.competition_id = :competitionId
        AND e ->> 'type' = '{$type}'
        AND e ->> 'hidden' = 'false'
        AND jsonb_array_length(e -> 'when' -> 'phases') > 0
)
SQL;
        $messageElements = $shown('message');
        $spotlightElements = $shown('spotlight');

        /** @var array{message: null|string, recent_messages: null|string, displays: int|string, message_elements: int|string, spotlight_elements: int|string} $row */
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT
    dc.message,
    dc.recent_messages,
    (SELECT COUNT(*) FROM competition_display d WHERE d.competition_id = :competitionId) AS displays,
    {$messageElements} AS message_elements,
    {$spotlightElements} AS spotlight_elements
FROM (SELECT 1) AS one
LEFT JOIN competition_display_control dc ON dc.competition_id = :competitionId
SQL,
            ['competitionId' => $competitionId],
        );

        $recent = $row['recent_messages'] !== null ? json_decode($row['recent_messages'], true) : [];

        return [
            'message' => $row['message'],
            'recentMessages' => is_array($recent) ? array_values(array_filter($recent, is_string(...))) : [],
            'displays' => (int) $row['displays'],
            'elements' => [
                'message' => (int) $row['message_elements'],
                'spotlight' => (int) $row['spotlight_elements'],
            ],
        ];
    }
}
