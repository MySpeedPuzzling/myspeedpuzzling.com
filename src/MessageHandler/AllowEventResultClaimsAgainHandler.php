<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\AllowEventResultClaimsAgain;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The admin page "Claim blocks" (docs/features/event-results-import/README.md "Gates"): the player may claim again. The
 * removals stay in result_claim_log and still count - the next removal blocks them again.
 */
#[AsMessageHandler]
readonly final class AllowEventResultClaimsAgainHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(AllowEventResultClaimsAgain $message): void
    {
        $this->playerRepository->get($message->playerId)->unblockEventClaims();
    }
}
