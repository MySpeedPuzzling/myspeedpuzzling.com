<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\AnnouncementModal;

/**
 * Whether the browser reported an announcement modal as seen (player_modal_impression.seen_at). For the XP launch
 * modal that report is sent when the level was revealed (XpLaunchLevelRevealedController), so the reveal page asks it
 * whether the level was already shown. One indexed lookup (unique player_id + modal).
 */
readonly final class GetAnnouncementModalSeen
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function isSeen(string $playerId, AnnouncementModal $modal): bool
    {
        $seen = $this->database
            ->executeQuery(
                'SELECT 1 FROM player_modal_impression WHERE player_id = :playerId AND modal = :modal AND seen_at IS NOT NULL',
                ['playerId' => $playerId, 'modal' => $modal->value],
            )
            ->fetchOne();

        return $seen !== false;
    }
}
