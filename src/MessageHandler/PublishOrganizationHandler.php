<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\PublishOrganization;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Services\Organizations\CompetitionSubmittedMailer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Publishing a draft that still waits for approval submits it: it enters the approval queue and the admin is e-mailed
 * (docs/features/organizations/README.md "Drafts") - once: not again after going back to draft (submittedAt), never
 * for an admin's publish. Publishing a published organization changes nothing.
 */
#[AsMessageHandler]
readonly final class PublishOrganizationHandler
{
    public function __construct(
        private OrganizationRepository $organizationRepository,
        private CompetitionSubmittedMailer $competitionSubmittedMailer,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PublishOrganization $message): void
    {
        $organization = $this->organizationRepository->get($message->organizationId);

        if ($organization->isDraft === false) {
            return;
        }

        // Submitted before (created published, published once, approved): the admins were told then
        $wasSubmitted = $organization->submittedAt !== null;
        $organization->publish();
        $organization->markSubmitted($this->clock->now());

        if ($message->notifyAdmin && $wasSubmitted === false && $organization->isApproved() === false && $organization->isRejected() === false) {
            $this->competitionSubmittedMailer->notifyAdminOfOrganization(
                $organization->name,
                $organization->addedByPlayer->name ?? 'Unknown',
                $organization->region,
            );
        }
    }
}
