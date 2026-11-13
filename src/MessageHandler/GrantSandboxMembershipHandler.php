<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Exceptions\SandboxModeIsOff;
use SpeedPuzzling\Web\Message\GrantSandboxMembership;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMembership;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class GrantSandboxMembershipHandler
{
    public function __construct(
        private SandboxMode $sandboxMode,
        private PlayerRepository $playerRepository,
        private MembershipRepository $membershipRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws SandboxModeIsOff
     */
    public function __invoke(GrantSandboxMembership $message): void
    {
        $this->sandboxMode->assertOn();

        $player = $this->playerRepository->findByUserId($message->userId);

        if ($player === null) {
            return;
        }

        try {
            $this->membershipRepository->getByPlayerId($player->id->toString());

            return;
        } catch (MembershipNotFound) {
            // No row yet - the grant below
        }

        $this->membershipRepository->save(new Membership(
            Uuid::uuid7(),
            $player,
            $this->clock->now(),
            grantedUntil: SandboxMembership::grantedUntil(),
        ));
    }
}
