<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\OrganizationNotEmpty;
use SpeedPuzzling\Web\Message\DeleteOrganization;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Only an empty organization goes (docs/features/organizations/README.md, P4) - nothing is detached by accident. Its
 * team rows, follows and redirect rows cascade.
 */
#[AsMessageHandler]
readonly final class DeleteOrganizationHandler
{
    public function __construct(
        private OrganizationRepository $organizationRepository,
    ) {
    }

    /**
     * @throws OrganizationNotEmpty
     */
    public function __invoke(DeleteOrganization $message): void
    {
        $organization = $this->organizationRepository->get($message->organizationId);
        $items = $this->organizationRepository->countItems($organization);

        if ($items['series'] > 0 || $items['events'] > 0) {
            throw new OrganizationNotEmpty($items['series'], $items['events']);
        }

        $this->organizationRepository->delete($organization);
    }
}
