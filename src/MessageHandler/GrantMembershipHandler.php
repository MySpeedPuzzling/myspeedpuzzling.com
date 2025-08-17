<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerAlreadyHaveMembership;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\GrantMembership;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class GrantMembershipHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private MembershipRepository $membershipRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerAlreadyHaveMembership
     * @throws PlayerNotFound
     */
    public function __invoke(GrantMembership $message): void
    {

        $player = $this->playerRepository->get($message->playerId);

        try {
            $this->membershipRepository->getByPlayerId($message->playerId);

            throw new PlayerAlreadyHaveMembership();
        } catch (MembershipNotFound) {
            // We want to create new membership - ofc it is not found :-)
            $membership = new Membership(
                Uuid::uuid7(),
                $player,
                $this->clock->now(),
                endsAt: $message->endsAt,
            );

            $this->membershipRepository->save($membership);
        }
    }
}
