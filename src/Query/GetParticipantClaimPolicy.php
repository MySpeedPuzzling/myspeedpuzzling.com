<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Value\ParticipantClaimPolicy;

/**
 * The claim policy in effect at an event, read without loading the entity (docs/features/competitions-management/
 * participant-linking.md D3, R9) - the same rule as Competition::participantClaimPolicy() (ParticipantClaimPolicy::
 * resolve() over the same columns; online by EventIsOnline). One statement.
 */
readonly final class GetParticipantClaimPolicy
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{policy: ParticipantClaimPolicy, stored: null|ParticipantClaimPolicy, proposalsEffective: bool}
     *
     * @throws CompetitionNotFound
     */
    public function of(string $competitionId): array
    {
        $isOnline = EventIsOnline::sql('c', 'cs');

        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT c.participant_claims, c.competitor_pages, c.time_proposals, {$isOnline} AS is_online
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE c.id = :competitionId
SQL,
            ['competitionId' => $competitionId],
        );

        if ($row === false) {
            throw new CompetitionNotFound();
        }

        /** @var array{participant_claims: null|string, competitor_pages: bool, time_proposals: bool, is_online: bool} $row */
        return self::fromRow($row['participant_claims'], $row['competitor_pages'], $row['time_proposals'], $row['is_online']);
    }

    /**
     * @return array{policy: ParticipantClaimPolicy, stored: null|ParticipantClaimPolicy, proposalsEffective: bool}
     */
    public static function fromRow(null|string $stored, bool $competitorPages, bool $timeProposals, bool $online): array
    {
        $storedPolicy = $stored !== null ? ParticipantClaimPolicy::tryFrom($stored) : null;

        return [
            'policy' => ParticipantClaimPolicy::resolve($storedPolicy, $competitorPages, $timeProposals, $online),
            'stored' => $storedPolicy,
            'proposalsEffective' => ParticipantClaimPolicy::proposalsEffective($competitorPages, $timeProposals, $online),
        ];
    }
}
