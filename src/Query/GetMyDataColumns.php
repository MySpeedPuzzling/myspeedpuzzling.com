<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\MyDataColumns;

/**
 * Which columns of "My data" the signed-in player switched off (docs/features/my-data.md "The column chooser").
 */
readonly final class GetMyDataColumns
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function ofPlayer(string $playerId): MyDataColumns
    {
        if (Uuid::isValid($playerId) === false) {
            return MyDataColumns::fromStored(null);
        }

        return MyDataColumns::fromStored(
            $this->database->fetchOne('SELECT my_data_columns FROM player WHERE id = :playerId', ['playerId' => $playerId]),
        );
    }
}
