<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RemoveOrganizationMaintainer;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Idempotent: a player who is no maintainer changes nothing (the creator stays the creator).
 */
#[AsMessageHandler]
readonly final class RemoveOrganizationMaintainerHandler
{
    public function __construct(
        private OrganizationRepository $organizationRepository,
    ) {
    }

    public function __invoke(RemoveOrganizationMaintainer $message): void
    {
        $organization = $this->organizationRepository->get($message->organizationId);
        $playerId = strtolower($message->playerId);

        foreach ($organization->maintainers->toArray() as $maintainer) {
            if ($maintainer->id->toString() === $playerId) {
                $organization->maintainers->removeElement($maintainer);
            }
        }
    }
}
