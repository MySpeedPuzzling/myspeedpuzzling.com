<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\FeatureRequestVoter;
use SpeedPuzzling\Web\Value\FeatureRequestEmails;

readonly final class GetFeatureRequestVoters
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<FeatureRequestVoter>
     */
    public function excludingPlayer(string $featureRequestId, string $excludedPlayerId): array
    {
        $query = <<<SQL
SELECT DISTINCT ON (fv.voter_id)
    fv.voter_id AS player_id,
    ua.email AS email,
    p.locale AS locale
FROM feature_request_vote fv
JOIN player p ON fv.voter_id = p.id
JOIN user_account ua ON ua.user_id = p.user_id
WHERE fv.feature_request_id = :featureRequestId
    -- As text: an anonymised author is passed as '', which is no uuid
    AND fv.voter_id::text != :excludedPlayerId
    AND p.feature_request_emails <> :none
ORDER BY fv.voter_id, fv.voted_at DESC
SQL;

        /** @var list<array{player_id: string, email: string, locale: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'featureRequestId' => $featureRequestId,
            'excludedPlayerId' => $excludedPlayerId,
            'none' => FeatureRequestEmails::None->value,
        ]);

        return $this->toVoters($rows);
    }

    /**
     * Players who get the e-mails about every feature request (FeatureRequestEmails::All) and neither suggested nor
     * upvoted this one - those get their own e-mail.
     *
     * @return list<FeatureRequestVoter>
     */
    public function followersOfAllRequests(string $featureRequestId, string $excludedPlayerId): array
    {
        $query = <<<SQL
SELECT
    p.id AS player_id,
    ua.email AS email,
    p.locale AS locale
FROM player p
JOIN user_account ua ON ua.user_id = p.user_id
WHERE p.feature_request_emails = :all
    AND p.id::text != :excludedPlayerId
    AND NOT EXISTS (
        SELECT 1 FROM feature_request_vote fv
        WHERE fv.feature_request_id = :featureRequestId AND fv.voter_id = p.id
    )
ORDER BY p.id
SQL;

        /** @var list<array{player_id: string, email: string, locale: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'featureRequestId' => $featureRequestId,
            'excludedPlayerId' => $excludedPlayerId,
            'all' => FeatureRequestEmails::All->value,
        ]);

        return $this->toVoters($rows);
    }

    /**
     * @param list<array{player_id: string, email: string, locale: null|string}> $rows
     * @return list<FeatureRequestVoter>
     */
    private function toVoters(array $rows): array
    {
        return array_map(
            static fn(array $row): FeatureRequestVoter => new FeatureRequestVoter(
                playerId: $row['player_id'],
                email: $row['email'],
                locale: $row['locale'],
            ),
            $rows,
        );
    }
}
