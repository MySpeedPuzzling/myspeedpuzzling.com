<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\UnpublishOrganization;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Always allowed: a draft organization hides only its own page, directory entry and "Organized by" links.
 */
#[AsMessageHandler]
readonly final class UnpublishOrganizationHandler
{
    public function __construct(
        private OrganizationRepository $organizationRepository,
    ) {
    }

    public function __invoke(UnpublishOrganization $message): void
    {
        $this->organizationRepository->get($message->organizationId)->unpublish();
    }
}
