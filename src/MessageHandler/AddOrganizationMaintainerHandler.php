<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\AddOrganizationMaintainer;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Idempotent: a player already on the team - its creator too (never a maintainer row) - changes nothing.
 */
#[AsMessageHandler]
readonly final class AddOrganizationMaintainerHandler
{
    public function __construct(
        private OrganizationRepository $organizationRepository,
        private PlayerRepository $playerRepository,
    ) {
    }

    public function __invoke(AddOrganizationMaintainer $message): void
    {
        $organization = $this->organizationRepository->get($message->organizationId);
        $player = $this->playerRepository->get($message->playerId);

        if ($organization->isOnTeam($player)) {
            return;
        }

        $organization->maintainers->add($player);
    }
}
