<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

/**
 * Runs DeletePlayer nested (like ConfirmAccountDeletion does) and then fails,
 * so the whole deletion rolls back - see DeletePlayerThenFailHandler.
 */
readonly final class DeletePlayerThenFail
{
    public function __construct(
        public string $playerId,
    ) {
    }
}
