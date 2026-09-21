<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Exceptions\FreeTrialNotAvailable;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\StartFreeTrial;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\FreeTrialSettings;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class StartFreeTrialHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private MembershipRepository $membershipRepository,
        private FreeTrialSettings $freeTrialSettings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws FreeTrialNotAvailable
     * @throws PlayerNotFound
     */
    public function __invoke(StartFreeTrial $message): void
    {
        if ($this->freeTrialSettings->isEnabled() === false) {
            throw new FreeTrialNotAvailable();
        }

        $player = $this->playerRepository->get($message->playerId);

        // A membership row is only ever created by a subscription, a voucher, an admin grant or a
        // trial - so its absence is exactly "never had a membership, never tried it". Two requests
        // racing past this check are stopped by the unique index on membership.player_id.
        try {
            $this->membershipRepository->getByPlayerId($message->playerId);

            throw new FreeTrialNotAvailable();
        } catch (MembershipNotFound) {
            $this->membershipRepository->save(
                Membership::startFreeTrial(Uuid::uuid7(), $player, $this->clock->now(), $message->source),
            );
        }
    }
}
