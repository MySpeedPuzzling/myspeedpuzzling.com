<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\PublishOrganization;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Services\Organizations\CompetitionSubmittedMailer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Publishing a draft that still waits for approval submits it: it enters the approval queue and the admin is e-mailed
 * (docs/features/organizations/README.md "Drafts"). Publishing a published organization changes nothing.
 */
#[AsMessageHandler]
readonly final class PublishOrganizationHandler
{
    public function __construct(
        private OrganizationRepository $organizationRepository,
        private CompetitionSubmittedMailer $competitionSubmittedMailer,
    ) {
    }

    public function __invoke(PublishOrganization $message): void
    {
        $organization = $this->organizationRepository->get($message->organizationId);

        if ($organization->isDraft === false) {
            return;
        }

        $organization->publish();

        if ($organization->isApproved() === false && $organization->isRejected() === false) {
            $this->competitionSubmittedMailer->notifyAdmin(
                $organization->name,
                $organization->addedByPlayer->name ?? 'Unknown',
                $organization->region,
            );
        }
    }
}
