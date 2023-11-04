<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\UserProfile;

readonly final class GetUserProfile
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function byId(string $userId): UserProfile
    {
        $query = <<<SQL
SELECT user_id, email
FROM player
WHERE player.user_id = :userId
SQL;

        /**
         * @var null|array{
         *     user_id: string,
         *     email: null|string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'userId' => $userId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PlayerNotFound();
        }

        return UserProfile::fromDatabaseRow($row);
    }
}
