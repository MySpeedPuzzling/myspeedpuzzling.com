<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\ParticipantLinkAction;

/**
 * Refused pairs (docs/features/competitions-management/participant-linking.md D11, §3.4): a participant × player the log
 * says no to - the organiser declined the player's request, said "Not this person", or the player said "Not me". That
 * player can no longer claim that row and the import never links it (`link_refused_before`); the organiser's explicit
 * link in the sheet ignores refusals. Three refusals by the organiser (declined + "Not this person") of one player at
 * one event end their claims there. Kept forever - a decline is not undone by time.
 */
readonly final class GetRefusedParticipantLinks
{
    /** Refusals by the organiser that end a player's claims at the event (D11) */
    public const int REFUSALS_UNTIL_NO_MORE_CLAIMS = 3;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * The log's actions that make a refused pair - for SQL `IN (...)`, constants only.
     */
    public static function sqlRefusingActions(): string
    {
        return implode(', ', array_map(
            static fn (ParticipantLinkAction $action): string => "'" . $action->value . "'",
            [ParticipantLinkAction::Declined, ParticipantLinkAction::NotMe, ParticipantLinkAction::NotThisPerson],
        ));
    }

    /**
     * One statement: is (participant, player) a refused pair, and how many of the player's links the organiser refused at
     * the event.
     *
     * @return array{refusedPair: bool, organiserRefusals: int}
     */
    public function forClaim(string $competitionId, string $participantId, string $playerId): array
    {
        $refusing = self::sqlRefusingActions();
        $declined = ParticipantLinkAction::Declined->value;
        $notThisPerson = ParticipantLinkAction::NotThisPerson->value;

        /** @var array{refused_pair: bool, organiser_refusals: int|string} $row */
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT
    EXISTS (
        SELECT 1 FROM participant_link_log l
        WHERE l.competition_id = :competitionId AND l.participant_id = :participantId AND l.player_id = :playerId AND l.action IN ({$refusing})
    ) AS refused_pair,
    (
        SELECT COUNT(*) FROM participant_link_log l
        WHERE l.competition_id = :competitionId AND l.player_id = :playerId AND l.action IN ('{$declined}', '{$notThisPerson}')
    ) AS organiser_refusals
SQL,
            ['competitionId' => $competitionId, 'participantId' => $participantId, 'playerId' => $playerId],
        );

        return ['refusedPair' => $row['refused_pair'], 'organiserRefusals' => (int) $row['organiser_refusals']];
    }
}
