<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\OAuth2ClientRequestOverview;

final readonly class GetOAuth2ClientRequests
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<OAuth2ClientRequestOverview>
     */
    public function all(): array
    {
        $query = <<<SQL
SELECT
    r.id, r.client_name, r.client_description, r.purpose, r.application_type,
    r.requested_scopes, r.redirect_uris, r.status, r.created_at, r.reviewed_at,
    r.rejection_reason, r.client_identifier, r.credentials_claimed,
    p.name AS player_name
FROM oauth2_client_request r
INNER JOIN player p ON p.id = r.player_id
ORDER BY
    CASE r.status WHEN 'pending' THEN 0 ELSE 1 END,
    r.created_at DESC
SQL;

        $rows = $this->database->executeQuery($query)->fetchAllAssociative();

        return array_map($this->mapRow(...), $rows);
    }

    /**
     * @return array<OAuth2ClientRequestOverview>
     */
    public function byPlayerId(string $playerId): array
    {
        $query = <<<SQL
SELECT
    r.id, r.client_name, r.client_description, r.purpose, r.application_type,
    r.requested_scopes, r.redirect_uris, r.status, r.created_at, r.reviewed_at,
    r.rejection_reason, r.client_identifier, r.credentials_claimed,
    p.name AS player_name
FROM oauth2_client_request r
INNER JOIN player p ON p.id = r.player_id
WHERE r.player_id = :playerId
ORDER BY r.created_at DESC
SQL;

        $rows = $this->database->executeQuery($query, ['playerId' => $playerId])->fetchAllAssociative();

        return array_map($this->mapRow(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): OAuth2ClientRequestOverview
    {
        /** @var string $id */
        $id = $row['id'];
        /** @var string $playerName */
        $playerName = $row['player_name'] ?? 'Unknown';
        /** @var string $clientName */
        $clientName = $row['client_name'];
        /** @var string $clientDescription */
        $clientDescription = $row['client_description'];
        /** @var string $purpose */
        $purpose = $row['purpose'];
        /** @var string $applicationType */
        $applicationType = $row['application_type'];
        /** @var string $requestedScopesJson */
        $requestedScopesJson = $row['requested_scopes'];
        /** @var string $redirectUrisJson */
        $redirectUrisJson = $row['redirect_uris'];
        /** @var string $status */
        $status = $row['status'];
        /** @var string $createdAt */
        $createdAt = $row['created_at'];
        /** @var null|string $reviewedAt */
        $reviewedAt = $row['reviewed_at'];
        /** @var null|string $rejectionReason */
        $rejectionReason = $row['rejection_reason'];
        /** @var null|string $clientIdentifier */
        $clientIdentifier = $row['client_identifier'];

        /** @var array<string> $requestedScopes */
        $requestedScopes = json_decode($requestedScopesJson, true);
        /** @var array<string> $redirectUris */
        $redirectUris = json_decode($redirectUrisJson, true);

        return new OAuth2ClientRequestOverview(
            id: $id,
            playerName: $playerName,
            clientName: $clientName,
            clientDescription: $clientDescription,
            purpose: $purpose,
            applicationType: $applicationType,
            requestedScopes: $requestedScopes,
            redirectUris: $redirectUris,
            status: $status,
            createdAt: new DateTimeImmutable($createdAt),
            reviewedAt: $reviewedAt !== null ? new DateTimeImmutable($reviewedAt) : null,
            rejectionReason: $rejectionReason,
            clientIdentifier: $clientIdentifier,
            credentialsClaimed: (bool) $row['credentials_claimed'],
        );
    }
}
