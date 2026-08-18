<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ConnectedOauthIdentity;
use SpeedPuzzling\Web\Value\OauthProvider;

readonly final class GetOauthIdentities
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<ConnectedOauthIdentity>
     */
    public function byUserId(string $userId): array
    {
        $query = <<<SQL
SELECT oauth_identity.provider, oauth_identity.email_at_link, oauth_identity.linked_at, oauth_identity.last_used_at
FROM oauth_identity
INNER JOIN user_account ON user_account.id = oauth_identity.user_account_id
WHERE user_account.user_id = :userId
ORDER BY oauth_identity.linked_at
SQL;

        $data = $this->database
            ->executeQuery($query, ['userId' => $userId])
            ->fetchAllAssociative();

        return array_map(static function (array $row): ConnectedOauthIdentity {
            /** @var array{
             *     provider: string,
             *     email_at_link: null|string,
             *     linked_at: string,
             *     last_used_at: null|string,
             * } $row */

            return new ConnectedOauthIdentity(
                provider: OauthProvider::from($row['provider']),
                emailAtLink: $row['email_at_link'],
                linkedAt: new DateTimeImmutable($row['linked_at']),
                lastUsedAt: $row['last_used_at'] === null ? null : new DateTimeImmutable($row['last_used_at']),
            );
        }, $data);
    }
}
